# Changelog

## 0.1.0 (unreleased)

- Behat extension with an `in_process` driver (the app's `Tui` on a `VirtualTerminal`, the Revolt loop turned in slices) and a `process` driver (the real command in a pseudo-terminal)
- `TuiContext` steps: start, type, press keys with modifiers, send mixed text and keys, paste, resize, wait, stop; see, not see, regex, line, whole screen, snapshots, exit and exit code; widget focus and value (in process)
- Mouse steps: click, double, triple, right and middle click, with modifiers, on an element, on text or at a cell; wheel scrolling; dragging. They send SGR (mode 1006) reports, as a terminal does
- `TuiProbe`, which an application's entry point installs to share its widgets with the process driver over a Unix socket, so CSS selectors and focus steps work on both drivers
- CSS selectors for widgets, through symfony/css-selector: element names from widget classes, id, style classes, label, value, `:focus`, `:visible`, `:contains()`; steps to check text in an element, count, visibility, what an element shows, focus and value
- Region checks, `I should eventually see`, `the screen should match:`
- The `Tuink` manager is injected into context constructors that ask for it by type, and into `TuinkAwareContext`s
- `@in_process` and `@process` tags switch the driver per scenario or feature
- Steps wait for late frames up to `timeout`; failures print the screen with row numbers
