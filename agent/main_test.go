package main

import (
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

const allowedOrigin = "https://admin.example.com"

type fakePrinter struct {
	listener net.Listener
	received chan []byte
}

func newFakePrinter(t *testing.T) *fakePrinter {
	t.Helper()
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	p := &fakePrinter{listener: l, received: make(chan []byte, 1)}
	go func() {
		conn, err := l.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		data, _ := io.ReadAll(conn)
		p.received <- data
	}()
	t.Cleanup(func() { l.Close() })

	return p
}

func (p *fakePrinter) addr() string { return p.listener.Addr().String() }

func (p *fakePrinter) port() int { return p.listener.Addr().(*net.TCPAddr).Port }

func testHandler(t *testing.T, printers ...string) http.Handler {
	t.Helper()
	cfg := &Config{AllowedOrigins: []string{allowedOrigin}, AllowedPrinters: printers, DialTimeoutSeconds: 1}
	if err := cfg.normalize(); err != nil {
		t.Fatal(err)
	}

	return newHandler(cfg, log.New(io.Discard, "", 0))
}

func do(h http.Handler, method, path, body string, headers map[string]string) *httptest.ResponseRecorder {
	req := httptest.NewRequest(method, path, strings.NewReader(body))
	req.Host = "127.0.0.1:9101"
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)

	return rec
}

func printBody(host string, port int, zpl string) string {
	return `{"host":"` + host + `","port":` + strconv.Itoa(port) + `,"zpl":` + quote(zpl) + `}`
}

func quote(s string) string {
	r := strings.NewReplacer(`\`, `\\`, `"`, `\"`, "\n", `\n`)

	return `"` + r.Replace(s) + `"`
}

func TestPrintForwardsExactBytesToThePrinter(t *testing.T) {
	printer := newFakePrinter(t)
	h := testHandler(t, printer.addr())
	zpl := "^XA\n^FDcafé ~ test^FS\n^PQ2,0,1,N\n^XZ\n"

	rec := do(h, http.MethodPost, "/print", printBody("127.0.0.1", printer.port(), zpl), map[string]string{"Origin": allowedOrigin, "Content-Type": "application/json"})

	if rec.Code != http.StatusNoContent {
		t.Fatalf("status = %d, body %q", rec.Code, rec.Body.String())
	}
	if got := rec.Header().Get("Access-Control-Allow-Origin"); got != allowedOrigin {
		t.Fatalf("Access-Control-Allow-Origin = %q", got)
	}
	select {
	case data := <-printer.received:
		if string(data) != zpl {
			t.Fatalf("printer received %q, want %q", data, zpl)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("printer received nothing")
	}
}

func TestPrintRejectsDisallowedOrigin(t *testing.T) {
	printer := newFakePrinter(t)
	h := testHandler(t, printer.addr())

	rec := do(h, http.MethodPost, "/print", printBody("127.0.0.1", printer.port(), "^XA^XZ"), map[string]string{"Origin": "https://evil.example"})

	if rec.Code != http.StatusForbidden {
		t.Fatalf("status = %d", rec.Code)
	}
	if rec.Header().Get("Access-Control-Allow-Origin") != "" {
		t.Fatal("CORS header must not be set for a disallowed origin")
	}
}

func TestPrintRequiresAnOrigin(t *testing.T) {
	printer := newFakePrinter(t)
	h := testHandler(t, printer.addr())

	rec := do(h, http.MethodPost, "/print", printBody("127.0.0.1", printer.port(), "^XA^XZ"), nil)

	if rec.Code != http.StatusForbidden {
		t.Fatalf("status = %d", rec.Code)
	}
}

func TestPrintRejectsPrinterThatIsNotListed(t *testing.T) {
	printer := newFakePrinter(t)
	h := testHandler(t, "172.16.0.22:9100")

	rec := do(h, http.MethodPost, "/print", printBody("127.0.0.1", printer.port(), "^XA^XZ"), map[string]string{"Origin": allowedOrigin})

	if rec.Code != http.StatusForbidden {
		t.Fatalf("status = %d", rec.Code)
	}
	select {
	case <-printer.received:
		t.Fatal("the printer must not receive data")
	case <-time.After(200 * time.Millisecond):
	}
}

func TestPrintReturnsBadGatewayWhenPrinterIsDown(t *testing.T) {
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	addr := l.Addr().String()
	port := l.Addr().(*net.TCPAddr).Port
	l.Close()
	h := testHandler(t, addr)

	rec := do(h, http.MethodPost, "/print", printBody("127.0.0.1", port, "^XA^XZ"), map[string]string{"Origin": allowedOrigin})

	if rec.Code != http.StatusBadGateway {
		t.Fatalf("status = %d", rec.Code)
	}
}

func TestPrintValidatesTheBody(t *testing.T) {
	h := testHandler(t, "127.0.0.1:9100")
	headers := map[string]string{"Origin": allowedOrigin}

	cases := map[string]string{
		"not json":       `{broken`,
		"missing zpl":    `{"host":"127.0.0.1","port":9100,"zpl":"  "}`,
		"missing host":   `{"port":9100,"zpl":"^XA^XZ"}`,
		"bad port":       `{"host":"127.0.0.1","port":70000,"zpl":"^XA^XZ"}`,
		"diagnostic ~!D": `{"host":"127.0.0.1","port":9100,"zpl":"~!D"}`,
	}
	for name, body := range cases {
		if rec := do(h, http.MethodPost, "/print", body, headers); rec.Code != http.StatusBadRequest {
			t.Errorf("%s: status = %d, want 400", name, rec.Code)
		}
	}
}

func TestPreflightAnswersCorsAndPrivateNetworkAccess(t *testing.T) {
	h := testHandler(t, "127.0.0.1:9100")

	rec := do(h, http.MethodOptions, "/print", "", map[string]string{
		"Origin":                                 allowedOrigin,
		"Access-Control-Request-Method":          "POST",
		"Access-Control-Request-Headers":         "content-type",
		"Access-Control-Request-Private-Network": "true",
	})

	if rec.Code != http.StatusNoContent {
		t.Fatalf("status = %d", rec.Code)
	}
	expected := map[string]string{
		"Access-Control-Allow-Origin":          allowedOrigin,
		"Access-Control-Allow-Private-Network": "true",
		"Access-Control-Allow-Headers":         "Content-Type",
	}
	for header, want := range expected {
		if got := rec.Header().Get(header); got != want {
			t.Errorf("%s = %q, want %q", header, got, want)
		}
	}
	if !strings.Contains(rec.Header().Get("Access-Control-Allow-Methods"), "POST") {
		t.Errorf("Access-Control-Allow-Methods = %q", rec.Header().Get("Access-Control-Allow-Methods"))
	}
}

func TestPreflightFromDisallowedOriginIsRefused(t *testing.T) {
	h := testHandler(t, "127.0.0.1:9100")

	rec := do(h, http.MethodOptions, "/print", "", map[string]string{"Origin": "https://evil.example", "Access-Control-Request-Private-Network": "true"})

	if rec.Code != http.StatusForbidden || rec.Header().Get("Access-Control-Allow-Private-Network") != "" {
		t.Fatalf("status = %d, headers = %v", rec.Code, rec.Header())
	}
}

func TestHealth(t *testing.T) {
	h := testHandler(t, "127.0.0.1:9100")

	rec := do(h, http.MethodGet, "/health", "", map[string]string{"Origin": allowedOrigin})
	if rec.Code != http.StatusOK || !strings.Contains(rec.Body.String(), "ok") {
		t.Fatalf("status = %d, body %q", rec.Code, rec.Body.String())
	}
	if rec.Header().Get("Access-Control-Allow-Origin") != allowedOrigin {
		t.Fatal("health must answer CORS for an allowed origin")
	}

	if rec := do(h, http.MethodGet, "/health", "", map[string]string{"Origin": "https://evil.example"}); rec.Code != http.StatusForbidden {
		t.Fatalf("disallowed origin status = %d", rec.Code)
	}
	if rec := do(h, http.MethodGet, "/health", "", nil); rec.Code != http.StatusOK {
		t.Fatalf("no-origin status = %d", rec.Code)
	}
}

func TestRequestsWithAForeignHostHeaderAreRefused(t *testing.T) {
	h := testHandler(t, "127.0.0.1:9100")
	req := httptest.NewRequest(http.MethodGet, "/health", nil)
	req.Host = "attacker.example:9101"
	rec := httptest.NewRecorder()

	h.ServeHTTP(rec, req)

	if rec.Code != http.StatusForbidden {
		t.Fatalf("status = %d", rec.Code)
	}
}

func TestMethodsAreRestricted(t *testing.T) {
	h := testHandler(t, "127.0.0.1:9100")

	if rec := do(h, http.MethodGet, "/print", "", map[string]string{"Origin": allowedOrigin}); rec.Code != http.StatusMethodNotAllowed {
		t.Fatalf("GET /print status = %d", rec.Code)
	}
	if rec := do(h, http.MethodPost, "/health", "", nil); rec.Code != http.StatusMethodNotAllowed {
		t.Fatalf("POST /health status = %d", rec.Code)
	}
}

func TestConfigValidation(t *testing.T) {
	valid := Config{AllowedOrigins: []string{"https://a.example/"}, AllowedPrinters: []string{"1.2.3.4:9100"}}
	if err := valid.normalize(); err != nil {
		t.Fatal(err)
	}
	if valid.Listen != "127.0.0.1:9101" || valid.AllowedOrigins[0] != "https://a.example" || valid.DialTimeoutSeconds != 5 {
		t.Fatalf("defaults not applied: %+v", valid)
	}

	invalid := map[string]Config{
		"non-loopback listen":  {Listen: "0.0.0.0:9101", AllowedOrigins: []string{"https://a.example"}, AllowedPrinters: []string{"1.2.3.4:9100"}},
		"wildcard origin":      {AllowedOrigins: []string{"*"}, AllowedPrinters: []string{"1.2.3.4:9100"}},
		"no origins":           {AllowedPrinters: []string{"1.2.3.4:9100"}},
		"no printers":          {AllowedOrigins: []string{"https://a.example"}},
		"printer without port": {AllowedOrigins: []string{"https://a.example"}, AllowedPrinters: []string{"1.2.3.4"}},
	}
	for name, cfg := range invalid {
		cfg := cfg
		if err := cfg.normalize(); err == nil {
			t.Errorf("%s: expected an error", name)
		}
	}
}

func TestLoadConfig(t *testing.T) {
	path := filepath.Join(t.TempDir(), "agent.json")
	if err := os.WriteFile(path, []byte(`{"allowedOrigins":["https://a.example"],"allowedPrinters":["172.16.0.22:9100"]}`), 0o600); err != nil {
		t.Fatal(err)
	}

	cfg, err := loadConfig(path)
	if err != nil || cfg.Listen != defaultListen {
		t.Fatalf("cfg = %+v, err = %v", cfg, err)
	}
	if _, err := loadConfig(filepath.Join(t.TempDir(), "missing.json")); err == nil {
		t.Fatal("expected an error for a missing file")
	}
}
