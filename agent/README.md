# Zangra label print agent

A tiny Windows (or Linux/macOS) program that forwards ready-made ZPL from the
browser to a label printer on raw TCP (port 9100).

```
Browser (https admin page) --1 XHR--> Sylius (renders the ZPL) --2 zpl--> Browser
Browser --3 POST http://127.0.0.1:9101/print--> agent --4 TCP 9100--> TSC printer
```

Sylius stays the only place that validates and renders. The agent is a dumb
pipe: it never renders anything and only talks to the printers you list.

**Why one agent per PC, on `127.0.0.1`?** An https page may not call an `http://`
LAN address (mixed content), but `http://127.0.0.1` is treated as a secure
context. So every PC that prints runs its own agent.

## Security model

- Binds to loopback only. `listen` must be a loopback address, otherwise the agent refuses to start.
- `POST /print` requires an `Origin` header that is listed in `allowedOrigins` (no wildcards).
- The target `host:port` must be listed in `allowedPrinters`.
- The `Host` header must be `127.0.0.1:<port>`, `localhost:<port>` or `[::1]:<port>` (blocks DNS rebinding).
- ZPL containing `~!D` is refused (it would put the printer in diagnostic mode).
- Answers CORS and Chrome Private Network Access preflights (`Access-Control-Allow-Private-Network: true`), for allowed origins only.

## HTTP API

| Request | Result |
| --- | --- |
| `GET /health` | `200 {"status":"ok"}` |
| `POST /print` `{"host":"172.16.0.22","port":9100,"zpl":"^XA...^XZ"}` | `204` printed, `400` bad body, `403` origin or printer not allowed, `502` printer unreachable |
| `OPTIONS /print` | `204` with CORS/PNA headers, `403` for an unknown origin |

## Configuration

Copy `agent.example.json` to `agent.json` next to `agent.exe` (or pass `-config <path>`):

```json
{
  "listen": "127.0.0.1:9101",
  "allowedOrigins": ["https://admin.example.com"],
  "allowedPrinters": ["172.16.0.22:9100"],
  "dialTimeoutSeconds": 5
}
```

- `allowedOrigins`: the exact origin(s) of your Sylius admin, `scheme://host[:port]`, no path.
- `allowedPrinters`: every `host:port` a printer in Sylius may point to.
- `listen` (default `127.0.0.1:9101`) must match the **Agent URL** of the printer in Sylius.

## Build

Needs Go 1.21 or newer. No third-party modules.

```bash
cd agent
go test ./...

# Windows 64 bit
GOOS=windows GOARCH=amd64 go build -trimpath -ldflags "-s -w" -o dist/agent.exe .

# Linux / macOS, for local development
go build -o dist/agent .
```

## Install on a Windows PC

1. Create `C:\ZangraAgent\` and copy `agent.exe` and your `agent.json` into it.
2. Test it: run `C:\ZangraAgent\agent.exe` in a terminal, then open `http://127.0.0.1:9101/health` in a browser. You should see `{"status":"ok"}`.
3. Make it start automatically, using one of the two options below.

### Option A: Task Scheduler (no extra software)

Run in an elevated PowerShell:

```powershell
$action  = New-ScheduledTaskAction -Execute 'C:\ZangraAgent\agent.exe' -WorkingDirectory 'C:\ZangraAgent'
$trigger = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName 'ZangraPrintAgent' -Action $action -Trigger $trigger -Settings $settings -Description 'Zangra label print agent'
Start-ScheduledTask -TaskName 'ZangraPrintAgent'
```

Remove it with `Unregister-ScheduledTask -TaskName ZangraPrintAgent -Confirm:$false`.

### Option B: Windows service with NSSM

[NSSM](https://nssm.cc/) wraps a console program as a service. Run in an elevated terminal:

```bat
nssm install ZangraPrintAgent C:\ZangraAgent\agent.exe
nssm set ZangraPrintAgent AppDirectory C:\ZangraAgent
nssm set ZangraPrintAgent AppStderr C:\ZangraAgent\agent.log
nssm set ZangraPrintAgent AppRotateFiles 1
nssm set ZangraPrintAgent Start SERVICE_AUTO_START
nssm start ZangraPrintAgent
```

The agent logs to stderr, which NSSM writes to `agent.log`.

## Troubleshooting

| Symptom in the admin UI | Cause |
| --- | --- |
| "The print agent is not running on this computer" | The agent is not started, listens on another port than the printer's Agent URL, or the origin is not in `allowedOrigins` (the browser blocks the answer). Open `http://127.0.0.1:9101/health`. |
| "The print agent refused the job" | The printer `host:port` is missing in `allowedPrinters`. |
| "The print agent could not reach the printer" | The PC cannot open TCP to the printer. Check the IP/port and the cable/network. |
