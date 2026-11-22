# Changelog

Notable changes are recorded using [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Version numbers follow [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Behat extension with an `in_process` driver (the app's `Tui` on a `VirtualTerminal`, the Revolt loop turned in slices) and a `process` driver (the real command in a pseudo-terminal).
- `TuiContext` steps to start, type, press keys with modifiers, send text and keys together, paste, resize, wait, stop and restart the app.
- Screen checks: text, regular expressions, lines, regions, the whole screen, snapshots, text counts, and their negations; checks wait for late frames up to `timeout` and failures print the screen with row numbers.
- Steps after MinkContext: fill in fields (one or a table), select from a list, field and element contents, focus and values.
- CSS selectors for widgets through symfony/css-selector: element names from widget classes, id, style classes, label, value, selected item, `:focus`, `:visible`, `:contains()`.
- `TuiProbe`, which an application's entry point installs to share its widgets with the process driver over a Unix socket, so selector steps work on both drivers.
- Mouse steps: left, right, middle, double and triple clicks with modifiers, on an element, on text or at a cell; hover; wheel scrolling; dragging. They send SGR (mode 1006) reports, as a terminal does.
- Terminal steps: text styles (bold, italic and the other attributes, foreground and background colors), the bell and the window title.
- Key combinations without a legacy encoding, like Ctrl+Tab or Shift+Backspace, are sent in the CSI u form the Tui key parser reads.
- `@in_process` and `@process` tags switch the driver per scenario or feature.
- A `process.temp_dir` option for where the probe socket and the terminal name go, for systems whose temporary directory path is too long for a Unix socket.
- The `Tuink` manager is injected into context constructors that ask for it by type, and into contexts implementing `TuinkAwareContextInterface`.
- A workspace per scenario: a temporary directory the application finds in `TUINK_WORKSPACE` and steps name `%workspace%`, with steps to write files and directories, delete them, and check the files the application writes.
- Arguments and environment variables per scenario, given to the in-process factory as a `Launch` (and set in `$_SERVER` and `$_ENV` too, so the application's subprocesses get them) and to the process driver's command; a terminal that speaks the kitty keyboard protocol.
- Frame steps that read, check and click inside the frame whose top border carries a title, and steps for where texts are relative to each other.
- The process driver puts the application's standard error on the terminal, as a terminal does, so console questions and errors written there show.
- The process driver ends an application whose test run died without stopping it, like a test runner killed on timeout, instead of leaving it running.
- Starting the app waits up to `start_timeout` for it to put the terminal in raw mode, longer than a step, so keys do not reach a terminal it has not taken over yet; one that stays in line mode goes on then if it wrote, and fails to start if it did not.
- Terminal state steps: the clipboard (OSC 52), DEC private modes (mouse, bracketed paste, alternate screen, focus events), the cursor's visibility, position and shape, and focus in and out reports.
