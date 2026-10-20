// Command agent is a tiny local print agent. A browser on the atelier PC posts
// ready-made ZPL to it and the agent writes the bytes to the label printer on
// raw TCP (usually port 9100). It binds to loopback only and only accepts
// configured origins and printers.
package main

import (
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

const (
	defaultListen      = "127.0.0.1:9101"
	defaultDialTimeout = 5 * time.Second
	maxBodyBytes       = 1 << 20
	preflightMaxAge    = "600"
)

// Config is the content of agent.json.
type Config struct {
	Listen             string   `json:"listen"`
	AllowedOrigins     []string `json:"allowedOrigins"`
	AllowedPrinters    []string `json:"allowedPrinters"`
	DialTimeoutSeconds int      `json:"dialTimeoutSeconds"`
}

func loadConfig(path string) (*Config, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}

	cfg := &Config{}
	if err := json.Unmarshal(raw, cfg); err != nil {
		return nil, fmt.Errorf("invalid JSON in %s: %w", path, err)
	}
	if err := cfg.normalize(); err != nil {
		return nil, fmt.Errorf("invalid configuration in %s: %w", path, err)
	}

	return cfg, nil
}

func (c *Config) normalize() error {
	if c.Listen == "" {
		c.Listen = defaultListen
	}
	host, port, err := net.SplitHostPort(c.Listen)
	if err != nil {
		return fmt.Errorf("listen %q is not host:port", c.Listen)
	}
	if n, err := strconv.Atoi(port); err != nil || n < 1 || n > 65535 {
		return fmt.Errorf("listen port %q is invalid", port)
	}
	if ip := net.ParseIP(host); host != "localhost" && (ip == nil || !ip.IsLoopback()) {
		return fmt.Errorf("listen address %q must be a loopback address", host)
	}

	origins := make([]string, 0, len(c.AllowedOrigins))
	for _, origin := range c.AllowedOrigins {
		origin = strings.TrimRight(strings.TrimSpace(origin), "/")
		if origin == "" || origin == "*" || !strings.Contains(origin, "://") {
			return fmt.Errorf("allowedOrigins entry %q must look like https://host[:port]", origin)
		}
		origins = append(origins, origin)
	}
	if len(origins) == 0 {
		return errors.New("allowedOrigins must contain at least one origin")
	}
	c.AllowedOrigins = origins

	if len(c.AllowedPrinters) == 0 {
		return errors.New("allowedPrinters must contain at least one host:port")
	}
	for _, printer := range c.AllowedPrinters {
		if _, _, err := net.SplitHostPort(printer); err != nil {
			return fmt.Errorf("allowedPrinters entry %q is not host:port", printer)
		}
	}

	if c.DialTimeoutSeconds <= 0 {
		c.DialTimeoutSeconds = int(defaultDialTimeout / time.Second)
	}

	return nil
}

type printRequest struct {
	Host string `json:"host"`
	Port int    `json:"port"`
	ZPL  string `json:"zpl"`
}

type server struct {
	origins     map[string]bool
	printers    map[string]bool
	dialTimeout time.Duration
	hosts       map[string]bool
	logger      *log.Logger
}

func newHandler(cfg *Config, logger *log.Logger) http.Handler {
	s := &server{
		origins:     map[string]bool{},
		printers:    map[string]bool{},
		dialTimeout: time.Duration(cfg.DialTimeoutSeconds) * time.Second,
		hosts:       map[string]bool{},
		logger:      logger,
	}
	for _, origin := range cfg.AllowedOrigins {
		s.origins[origin] = true
	}
	for _, printer := range cfg.AllowedPrinters {
		s.printers[strings.ToLower(printer)] = true
	}
	_, port, _ := net.SplitHostPort(cfg.Listen)
	for _, host := range []string{"127.0.0.1", "localhost", "[::1]"} {
		s.hosts[host+":"+port] = true
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/health", s.health)
	mux.HandleFunc("/print", s.print)

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Rejects DNS-rebinding: browsers send the attacker's hostname in Host.
		if !s.hosts[strings.ToLower(r.Host)] {
			http.Error(w, "forbidden host", http.StatusForbidden)

			return
		}
		mux.ServeHTTP(w, r)
	})
}

// cors answers the CORS and Private Network Access handshake. It returns true
// when the request has been fully handled (preflight or rejection).
func (s *server) cors(w http.ResponseWriter, r *http.Request, requireOrigin bool) bool {
	origin := r.Header.Get("Origin")
	if origin == "" {
		if requireOrigin || r.Method == http.MethodOptions {
			http.Error(w, "origin required", http.StatusForbidden)

			return true
		}

		return false
	}
	if !s.origins[strings.TrimRight(origin, "/")] {
		s.logger.Printf("rejected origin %q on %s %s", origin, r.Method, r.URL.Path)
		http.Error(w, "origin not allowed", http.StatusForbidden)

		return true
	}

	h := w.Header()
	h.Set("Access-Control-Allow-Origin", origin)
	h.Add("Vary", "Origin")

	if r.Method == http.MethodOptions {
		h.Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
		h.Set("Access-Control-Allow-Headers", "Content-Type")
		h.Set("Access-Control-Max-Age", preflightMaxAge)
		if r.Header.Get("Access-Control-Request-Private-Network") == "true" {
			h.Set("Access-Control-Allow-Private-Network", "true")
		}
		w.WriteHeader(http.StatusNoContent)

		return true
	}

	return false
}

func (s *server) health(w http.ResponseWriter, r *http.Request) {
	if s.cors(w, r, false) {
		return
	}
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)

		return
	}
	w.Header().Set("Content-Type", "application/json")
	_, _ = io.WriteString(w, `{"status":"ok"}`)
}

func (s *server) print(w http.ResponseWriter, r *http.Request) {
	if s.cors(w, r, true) {
		return
	}
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)

		return
	}

	var req printRequest
	decoder := json.NewDecoder(http.MaxBytesReader(w, r.Body, maxBodyBytes))
	if err := decoder.Decode(&req); err != nil {
		http.Error(w, "invalid JSON body", http.StatusBadRequest)

		return
	}
	if req.Host == "" || req.Port < 1 || req.Port > 65535 || strings.TrimSpace(req.ZPL) == "" {
		http.Error(w, "host, port and zpl are required", http.StatusBadRequest)

		return
	}
	// ~!D would put the printer in diagnostic mode (see docs/printer-setup.md).
	if strings.Contains(req.ZPL, "~!D") {
		http.Error(w, "~!D commands are not allowed", http.StatusBadRequest)

		return
	}

	target := net.JoinHostPort(req.Host, strconv.Itoa(req.Port))
	if !s.printers[strings.ToLower(target)] {
		s.logger.Printf("rejected printer %s", target)
		http.Error(w, "printer not allowed", http.StatusForbidden)

		return
	}

	if err := s.send(target, []byte(req.ZPL)); err != nil {
		s.logger.Printf("print to %s failed: %v", target, err)
		http.Error(w, "cannot reach the printer", http.StatusBadGateway)

		return
	}

	s.logger.Printf("sent %d bytes to %s", len(req.ZPL), target)
	w.WriteHeader(http.StatusNoContent)
}

func (s *server) send(target string, payload []byte) error {
	conn, err := net.DialTimeout("tcp", target, s.dialTimeout)
	if err != nil {
		return err
	}
	defer conn.Close()

	if err := conn.SetWriteDeadline(time.Now().Add(s.dialTimeout * 2)); err != nil {
		return err
	}
	_, err = conn.Write(payload)

	return err
}

func defaultConfigPath() string {
	exe, err := os.Executable()
	if err != nil {
		return "agent.json"
	}

	return filepath.Join(filepath.Dir(exe), "agent.json")
}

func main() {
	configPath := flag.String("config", defaultConfigPath(), "path to agent.json")
	flag.Parse()

	logger := log.New(os.Stderr, "agent: ", log.LstdFlags)

	cfg, err := loadConfig(*configPath)
	if err != nil {
		logger.Fatalf("cannot load configuration: %v", err)
	}

	srv := &http.Server{
		Addr:              cfg.Listen,
		Handler:           newHandler(cfg, logger),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       15 * time.Second,
		WriteTimeout:      30 * time.Second,
	}
	logger.Printf("listening on http://%s, %d origin(s), %d printer(s)", cfg.Listen, len(cfg.AllowedOrigins), len(cfg.AllowedPrinters))
	if err := srv.ListenAndServe(); err != nil {
		logger.Fatal(err)
	}
}
