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
- The `Tuink` manager is injected into context constructors that ask for it by type, and into contexts implementing `TuinkAwareContextInterface`.
