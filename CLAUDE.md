# CLAUDE.md — jee4heat

Jeedom plugin to control a Godin Artemis pellet stove (and generic
4Heat / TiEmme Elettronica stoves) over the local network.
Repo root = plugin root (this is what Jeedom expects).
Target: Jeedom 4.5 and 4.6 (PHP 8.2+).

## Author context (apply silently, no need to restate)
- Confirmed professional dev, not a beginner. Skip explanations of basics.
- The project is PHP (Jeedom frontend/backend + vanilla JS for the desktop UI).
- Step-by-step reasoning expected. Ask before assuming on ambiguous specs
  (esp. the 4Heat register/payload shapes — they are partly undocumented).
- Be concise. Don't summarize what already works — only report
  changes/diffs/issues. No restating unchanged code.
- All code comments in English, regardless of conversation language (French).

## Key conventions / gotchas
- French UI strings/log messages throughout PHP — keep French for
  user-facing strings (`{{...}}` Jeedom i18n tags, `log::add` messages can
  stay French to match existing style), but **code comments in English only**
  per author preference above.
- PHP 8.2+ target: guard every `$ARRAY[$key]` against undefined keys
  (`?? default`), and every `->method()` against non-objects with
  `is_object()` — these are the recurring bug class in this codebase.
- The eqLogic class is `jee4heat`, the cmd class is `jee4heatCmd`
  (both in `core/class/jee4heat.class.php`). IDE "Undefined method"
  warnings on `eqLogic`/`cmd` helpers are false positives (Jeedom core
  isn't on the analyzer's path).

## Architecture / protocol
- The stove speaks a proprietary ASCII protocol over a raw TCP socket
  on port 80 (`SOCKET_PORT`), NOT HTTP. PHP `socket_*` API is used
  directly. Sockets now set `SO_RCVTIMEO`/`SO_SNDTIMEO` (5 s) so an
  unreachable stove can't hang the cron.
- Message format: `["SEL","<n items>","ITEM1",...,"ITEMn"]`.
  Each register item is `<prefix><RRRRR><VVVVVVVVVVVV>` — 1 prefix char
  (e.g. `J`), 5-digit register number, 12-digit value (leading zeros).
  Some values must be `/100` (temperatures); see `generic.json`.
- Read flow: `DATA_QUERY` (`["SEL","0"]`) → stove returns all registers
  → `readregisters()` decodes and pushes values into info commands named
  `jee4heat_<register>`.
- Commands (no meaningful reply, fire-and-forget): `ON_CMD`, `OFF_CMD`,
  `UNBLOCK_CMD`, and `setStoveValue()` for the setpoint (value `*100`,
  zero-padded to 12 chars).
- State register (`STATE_REGISTER` 30001) drives `jee4heat_stovestate`,
  `jee4heat_mode`, `jee4heat_stovemessage`, `jee4heat_stoveblocked`.
  State `9` = blocked (e.g. out of pellets / door open) → exposes the
  unblock action. Error register `ERROR_REGISTER` (30002) → message via
  `ERROR_NAMES`.
- Polling: `cron()` runs every minute per enabled eqLogic. Stove-side
  propagation lag is 1–5 min (longer through the vendor cloud), so don't
  expect immediate feedback after a write.

## Files
- `core/class/jee4heat.class.php` — all backend logic (socket I/O,
  register decode, command/action creation in `postSave`, widget templates).
- `core/config/devices/*.json` — per-model register maps
  (`generic.json`, `godin_artemis.json`). To add a register/attribute,
  edit the JSON; `postSave` reads it and creates the commands.
- `desktop/php/jee4heat.php` + `desktop/js/jee4heat.js` — config UI.
- `core/ajax/jee4heat.ajax.php` — ajax endpoint (currently no live action).
- `plugin_info/info.json` — `require: 4.4` (min version; covers 4.5/4.6).
