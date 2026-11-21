# tuink

[![CI](https://github.com/janalis/tuink/actions/workflows/ci.yml/badge.svg)](https://github.com/janalis/tuink/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/janalis/tuink?sort=semver)](https://github.com/janalis/tuink/releases)
[![Mutation testing: 100% MSI](https://img.shields.io/badge/MSI-100%25-brightgreen)](infection.json5.dist)
[![MIT license](https://img.shields.io/github/license/janalis/tuink)](LICENSE)

End-to-end tests for [Symfony Tui](https://github.com/symfony/tui) applications, written in Gherkin and run by [Behat](https://behat.org).

```gherkin
Feature: Signing in

  Scenario: A greeting after the name
    Given I start the app in a 80x24 terminal
    When I type "Ada"
    And I press "Enter"
    Then I should see "Hello, Ada!"
    And the screen should match the snapshot "greeted"
```

The same scenario runs on two drivers:

| Driver       | Runs the app                                       | Good for                                              |
|--------------|----------------------------------------------------|-------------------------------------------------------|
| `in_process` | in the Behat process, on a `VirtualTerminal`       | speed, reaching into the app from your own steps      |
| `process`    | as its own process, in a pseudo-terminal           | the real entry point: boot, raw mode, stdin, signals  |

## Install

```bash
composer require --dev janalis/tuink
```

PHP 8.4+, Behat 4, `symfony/tui` 8.2+. (No Behat 3 release accepts the Symfony 8 components Symfony Tui needs.) The `process` driver needs pseudo-terminals (Linux, macOS).

## Configure

`behat.dist.php`:

```php
use Behat\Config\Config;
use Behat\Config\Extension;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Janalis\Tuink\Context\TuiContext;
use Janalis\Tuink\ServiceContainer\TuinkExtension;

return (new Config())
    ->withProfile((new Profile('default'))
        ->withSuite((new Suite('default'))->withContexts(TuiContext::class))
        ->withExtension(new Extension(TuinkExtension::class, [
            'in_process' => ['factory' => 'tests/tui-factory.php'],
            'process' => ['command' => ['php', 'bin/app']],
        ])));
```

| Option              | Default                  | Meaning                                                                   |
|---------------------|--------------------------|---------------------------------------------------------------------------|
| `default_driver`    | `in_process` if set      | Driver of untagged scenarios                                              |
| `columns`, `rows`   | `80`, `24`               | Terminal size at start                                                    |
| `timeout`           | `2.0`                    | Seconds a step waits for the screen to show what it expects               |
| `start_timeout`     | `10.0`                   | Seconds a start waits for the app to put the terminal in raw mode; `0` does not wait |
| `quiet_period`      | `0.02` / `0.05`          | Seconds without output after which the app counts as settled             |
| `snapshots`         | `features/__snapshots__` | Snapshot directory                                                        |
| `in_process.factory`| —                        | PHP file returning `callable(VirtualTerminal $terminal, Launch $launch): Tui` |
| `process.command`   | —                        | Command, as a list or a string split on spaces; a profile's replaces the default one |
| `process.cwd`       | Behat base path          | Working directory; `%workspace%` is the scenario's [workspace](#workspace-arguments-and-environment) |
| `process.env`       | `{}`                     | Extra environment variables                                               |
| `process.probe_timeout` | `2.0`                | Seconds to wait for the application's `TuiProbe`                         |
| `process.temp_dir`  | system temp directory    | Where the probe socket and the terminal name go; keep the path short, as a Unix socket path is limited to about 100 bytes |

Relative paths are resolved from the Behat base path. Values are taken as written: a `%` is not a Behat parameter, so `+%Y` or `100%` reach the command unchanged.

### The in-process factory

Build the application on the terminal you are given, and do not start it:

```php
// tests/tui-factory.php
use App\Ui\Application;
use Janalis\Tuink\Launch;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

return static fn (VirtualTerminal $terminal, Launch $launch): Tui => Application::build($terminal, $launch->arguments);
```

The `Launch` carries what the scenario starts the application with: its arguments, its environment (already set with `putenv()` while the application runs, and restored after) and its workspace.

Your entry point does the same with the real terminal and calls `run()`. The driver starts the `Tui` and turns the Revolt event loop in short slices, so timers and async work run as they do under `run()`.

When your entry point does more around `run()`, such as starting servers, arming timers or saving a session after it returns, return an `InProcessApplicationInterface` instead of the `Tui`. The driver calls `boot()` before it starts the `Tui`, and `shutdown()` once the `Tui` stopped, at the end of the scenario or on `I restart the app`. A `boot()` that throws must undo its own work: the driver does not call `shutdown()` after it.

```php
use Janalis\Tuink\Driver\InProcessApplicationInterface;

return static fn (VirtualTerminal $terminal, Launch $launch): InProcessApplicationInterface => new class(new Application($terminal)) implements InProcessApplicationInterface {
    public function __construct(private readonly Application $app) {}
    public function getTui(): Tui { return $this->app->tui; }
    public function boot(): void { $this->app->boot(); }
    public function shutdown(): void { $this->app->shutdown(); }
};
```

## Steps

| Step                                                          | Notes                                                           |
|---------------------------------------------------------------|-----------------------------------------------------------------|
| `Given I start the app`                                       |                                                                 |
| `Given I start the app in a 100x30 terminal`                  |                                                                 |
| `Given I start the app with the arguments "%workspace%/src -v"` | After the configured command, split as a shell does with single quotes (`'a b'`, `--name='a b'`; a lone `'` is an error); `""` drops earlier arguments; also `in a 100x30 terminal with …` |
| `Given the environment variable "HOME" is "%workspace%/home"` | Before the start; see [Workspace](#workspace-arguments-and-environment) |
| `Given the terminal supports the kitty keyboard protocol`     | Before the start                                                |
| `Given a file "src/a.php" containing:` + doc string           | In the workspace; also `containing "text"`, `an empty file "…"`, `a directory "…"` |
| `When I delete "src/a.php"`                                   | Or `remove`; a file or a directory                              |
| `Then the file "out.txt" should [not] exist`                  | Waits for the app to write it                                   |
| `Then the file "out.txt" should [not] contain "text"`         | Both fail when the file does not exist                          |
| `Then the file "out.txt" should contain exactly:` + doc string | Trailing line breaks ignored                                   |
| `When I type "text"`                                          | Characters, literally                                           |
| `When I press "Enter"` / `When I press "Down" 3 times`        | See key names below                                             |
| `When I send "Ada<Tab>Lovelace<Enter>"`                       | Text and `<Key>` names mixed                                    |
| `When I paste "text"` / `When I paste:` + doc string          | Bracketed paste                                                 |
| `When I click "selector"`                                     | The middle of the one match; see [Mouse](#mouse)                |
| `When I click on the text "Save"`                             | Its first character, where the screen first shows it            |
| `When I click at line 5, column 12`                           |                                                                 |
| `When I click on the text "Open" in the dialog "File"`        | Inside the frame titled "File"; see [Frames](#frames)           |
| `When I double-click …`, `triple-click`, `right-click`, `middle-click` | Any of the three forms above                           |
| `When I click … with "Ctrl+Shift"`                            | Shift, Alt, Ctrl held                                           |
| `When I scroll down 3 times over "selector"`                  | `up`, `down`, `left`, `right`; or `at line 5, column 12`; `with "Ctrl"` |
| `When I drag "selector" to "selector"`                        | Or `from line 2, column 1 to line 9, column 40`                 |
| `When I hover [over] "selector"`                              | Or `over the text "…"`, `at line 5, column 12`; motion with no button |
| `When I fill in "selector" with "text"`                       | Focuses the field, clears its line (End, Ctrl+U), types         |
| `When I fill in the following:` + table                       | `\| selector \| text \|` rows                                    |
| `When I select "Blue" from "selector"`                        | Arrows to the item, then Enter                                  |
| `When I restart the app`                                      | Same terminal size                                              |
| `When I resize the terminal to 60x20`                         |                                                                 |
| `When I wait 1.5 seconds`                                     | Lets timers run                                                 |
| `When I stop the app`                                         | The workspace stays, to check what the app saved                |
| `When the terminal loses focus` / `gains [the] focus`         | Focus reports (`ESC [ O`, `ESC [ I`)                            |
| `Then I should see "text"` / `should not see`                 |                                                                 |
| `Then I should eventually see "text"`                         | Same as `should see`, which already waits; reads better         |
| `Then I should see text matching "/\d+ files/"`               |                                                                 |
| `Then I should see "Name" above "Email"`                      | Or `below`, `before`, `after` (reading order)                   |
| `Then I should [not] see the dialog "File"`                   | `box`, `frame`, `dialog`, `panel` and `window` are the same     |
| `Then I should [not] see "text" in the dialog "File"`         | Inside the frame only                                           |
| `Then the dialog "File" should show:` + doc string            | The inside of the frame                                         |
| `Then I should not see text matching "/…/"`                   |                                                                 |
| `Then I should see "text" 3 times`                            | Occurrences on the screen                                       |
| `Then line 3 should not contain "text"`                       |                                                                 |
| `Then the text "Saved" should [not] be bold`                  | Or `dim`, `italic`, `underlined`, `blinking`, `reversed`, `hidden`, `struck through` |
| `Then the text "Error" should be colored "red"`               | `red`, `bright red`, a palette index, `#rrggbb`                 |
| `Then the text "…" should have the background "#1e1e2e"`      |                                                                 |
| `Then the terminal should have rung the bell [2 times]`       | See [Bell and title](#bell-and-title)                           |
| `Then the terminal title should be "…"`                       | OSC 0 or 2                                                      |
| `Then the clipboard should contain "text"` / `should be:`     | What the app last copied with OSC 52                            |
| `Then nothing should have been copied to the clipboard`       |                                                                 |
| `Then the "bracketed paste" mode should be on`                | Or `off`; see [Terminal state](#clipboard-modes-and-cursor)     |
| `Then the cursor should be visible` / `hidden`                | The terminal's cursor                                           |
| `Then the cursor should be at line 4, column 7`               |                                                                 |
| `Then the cursor should be a blinking bar`                    | `[blinking] block`, `underline`, `bar`                          |
| `Then line 3 should contain "text"` / `line 3 should be "…"`  | Lines count from 1; trailing spaces ignored                     |
| `Then the screen should be:` / `should match:` + doc string   | Trailing spaces and blank lines ignored                         |
| `Then the region at line 5, column 3 should be:` + doc string | A rectangle as wide and tall as the doc string, by display cell |
| `Then the screen should match [the] snapshot "name"`          | Written on first run; see below                                 |
| `Then the app should be running`                              |                                                                 |
| `Then the app should have exited [with code 2]`               | Exit codes need the `process` driver                            |
| `Then print the screen`                                       | For debugging                                                   |
| `Then I should see "text" in "#sidebar .title"`               | See [CSS selectors](#css-selectors)                             |
| `Then I should not see "text" in "selector"`                  | CSS selector                                                    |
| `Then I should [not] see an element matching "selector"`      | Drawn in the last frame                                         |
| `Then there should be 3 elements matching "selector"`         | Drawn or not                                                    |
| `Then the element "selector" should show:` + doc string       | Exactly one match                                               |
| `Then the element "selector" should [not] contain "text"`     | Same as `I should [not] see "text" in "selector"`               |
| `Then the "selector" field should [not] contain "text"`       | The field's value                                               |
| `When I focus [the element] "selector"`                       | Exactly one match                                               |
| `Then the element "selector" should [not] be focused`         | Any match                                                       |
| `Then the element "selector" should have the value "text"`    | Exactly one match whose `getValue()` returns a string           |

Every action waits until the app's output has been quiet for `quiet_period`. Every check retries until `timeout`, so a frame that comes late, after an HTTP call or a timer, does not fail the step. A failure prints the screen with its row numbers:

```
The text "Goodbye" was not found on the screen.

Screen (80x24, in_process driver, running):
1 | Tuink demo
2 | Status: ready
…
```

### Mouse

Symfony Tui has no mouse events of its own: it hands the SGR reports a terminal sends in mode 1006 (`ESC [ < 0 ; 12 ; 5 M`) to the application as `InputEvent` data, and the application decides what a click means, usually by testing `$tui->getWidgetRect($widget)->contains($row, $column)`. The mouse steps send those reports, the same bytes a terminal would, so they work on both drivers:

- a click is a press and a release; a double or triple click repeats them;
- the wheel sends presses of buttons 64 to 67;
- a drag presses, moves one cell at a time with the button held (motion reports), and releases;
- Shift, Alt and Ctrl add 4, 8 and 16 to the button code.

Clicking text needs nothing from the application. Clicking an element needs CSS selectors, so on the `process` driver the application must install the probe. A real terminal only reports the mouse once the application enables it (`ESC [ ? 1000 h` and `ESC [ ? 1006 h`); tuink sends reports either way, so enable them in your entry point or users will not get what your tests click. The demo's `example/bin/demo` shows both, and `DemoApp::handleMouse()` a hit test.

### CSS selectors

Widgets can be found with CSS selectors. tuink mirrors the widget tree into an XML document and queries it with [symfony/css-selector](https://symfony.com/doc/current/components/css_selector.html), so the whole selector grammar works: combinators, `:not()`, `:nth-child()`, attribute operators. Each widget is an element:

| CSS                        | Matches                                                                                  |
|----------------------------|------------------------------------------------------------------------------------------|
| `input`, `select-list`     | The class name without `Widget`, in kebab-case; `App\Ui\FileTree` is `file-tree`         |
| `#name`                    | `setId('name')`                                                                          |
| `.sidebar`                 | `addStyleClass('sidebar')`                                                               |
| `[label="Path"]`           | `setLabel('Path')`                                                                       |
| `[value="Ada"]`            | Widgets whose `getValue()` returns a string                                              |
| `[widget$="FileTree"]`     | The fully qualified class name                                                           |
| `:focus`                   | The focused widget                                                                       |
| `:visible`                 | Widgets drawn in the last frame                                                          |
| `:contains("text")`        | Widgets showing the text, children included                                              |
| `:root`                    | The root container `Tui::add()` adds to                                                  |

The tree starts at `Tui::getRoot()`. symfony/tui releases without that method keep the root container private, and tuink reads it by reflection.

A widget's text is what the screen shows inside the rectangle it was drawn in, so `I should see "Saved" in "#status"` reads the screen, not the widget's properties.

```gherkin
@in_process
Scenario: The status bar
  Given I start the app
  When I press "Ctrl+S"
  Then I should see "Saved" in "#status-bar .message"
  And the element "tree:focus" should show:
    """
    ▾ src/
      Kernel.php
    """
```

In process, the driver reads the widgets itself. On the `process` driver, the application has to share them: install the probe in its entry point, before `run()`:

```php
use Janalis\Tuink\Probe\TuiProbe;

if (class_exists(TuiProbe::class)) { // tuink is a dev dependency
    TuiProbe::install($tui);
}
$tui->run();
```

The probe does nothing unless the process driver started the application. The driver then sets `TUINK_PROBE` to a Unix socket in a private temporary directory; the probe connects to it and answers the driver's requests for the widget tree, and to focus a widget, from the Revolt event loop. Element text still comes from the screen the driver decoded, so it is what a user would see. Without the probe, selector steps on the `process` driver wait `probe_timeout` once, then fail and say what to do.

### Styles

Style steps read the cells where the screen first shows the text and check every one of them. Colors are named after what the application wrote: `red` or `bright red` for the 16 standard colors (whether written as SGR 31 or as palette index 1), the palette index for the other 240 colors, `#rrggbb` for true color. A failure says what the text has, for instance `bold, #5f87ff`.

### Workspace, arguments and environment

Each scenario has a workspace: a fresh temporary directory, removed after the scenario. File steps write and read there, by paths relative to it; a path cannot leave it. The application finds it in `TUINK_WORKSPACE`, and `%workspace%` names it in the scenario's arguments and variables, and in `process.command`, `process.cwd` and `process.env`. It survives `I stop the app` and `I restart the app`, so a scenario can quit the application and check what it saved, or start it again on the same files.

```gherkin
Scenario: Saving
  Given a file "src/Kernel.php" containing:
    """
    <?php
    """
  And the environment variable "HOME" is "%workspace%/home"
  And I start the app with the arguments "%workspace%/src/Kernel.php"
  When I type "// edited"
  And I press "Ctrl+S"
  Then the file "src/Kernel.php" should contain "// edited"
```

Variables set by a scenario come after `process.env`. In process, they are set with `putenv()`, so the application reads them with `getenv()`. On the `process` driver, the application's standard error goes to the terminal too, as in a real one, so a console question it asks before its first frame shows on the screen.

### Frames

Dialogs and panels are often drawn as a frame with a title on its top border:

```
╭─ Open file ──────────╮
│ src/Kernel.php       │
╰──────────────────────╯
```

Frame steps find the first frame whose top border carries the title, in any box-drawing style, and look only inside it: `I should see "Kernel.php" in the dialog "Open file"` fails when the dialog lists nothing even if the editor behind it shows the name. `I click on the text "…" in the dialog "…"` clicks inside it, so a step needs no coordinates.

### Clipboard, modes and cursor

What the application tells the terminal besides its cells:

- the clipboard is the text of the last OSC 52 write (`ESC ] 52 ; c ; <base64> BEL`);
- a mode is on when the application set one of its DEC private modes: `alternate screen` (1049, 1047, 47), `mouse clicks` (1000, 1002, 1003), `mouse drags` (1002, 1003), `mouse motion` (1003), `SGR mouse` (1006), `bracketed paste` (2004), `focus events` (1004);
- the cursor is visible until the application hides it (`ESC [ ? 25 l`); its position is where the terminal's cursor is, which Symfony Tui moves to the focused input; its shape is the last DECSCUSR (`ESC [ 5 SP q`).

`VirtualTerminal` turns on no mode itself, so on the `in_process` driver a mode is on only when the application writes it; the real terminal of the `process` driver turns on bracketed paste.

With `the terminal supports the kitty keyboard protocol`, the `in_process` driver marks its `VirtualTerminal` as speaking it, and the `process` driver answers the application's query (`ESC [ ? u`) as kitty does, so Symfony Tui switches the protocol on.

### Bell and title

The bell counts BEL characters outside escape sequences; the title is the last one set with OSC 0 or OSC 2. On macOS, Symfony Tui's real terminal plays a sound for the bell instead of writing BEL, so there the `process` driver cannot see it; the `in_process` driver always can.

### Keys

Names are case-insensitive, with optional angle brackets: `Enter`, `Escape`, `Tab`, `Space`, `Backspace`, `Delete`, `Insert`, `Up`, `Down`, `Left`, `Right`, `Home`, `End`, `PageUp`, `PageDown` (or `Page_Up`, `Page_Down`), `F1`–`F12`, `FocusIn` and `FocusOut` (the reports a terminal sends when its window gains or loses the focus), or any single character. Modifiers join with `+`: `Ctrl+S`, `Shift+Tab`, `Alt+Left`, `Ctrl+Shift+Up`, `Super+S` (also `Cmd+S`; macOS's Command key, which only the kitty keyboard protocol carries, so every Super combination goes as CSI u). Combinations without a legacy encoding, like `Ctrl+Tab`, `Shift+Backspace`, `Ctrl+Shift+A` or `Ctrl+,`, go in the CSI u form of the kitty keyboard protocol, which the Symfony Tui key parser reads. In `I send`, a key is a word of two letters or more (`<Enter>`, `<F5>`) or carries a modifier (`<Alt+x>`); `<b>` stays text.

### Snapshots

A missing snapshot is written from the current screen and the step passes; commit it. When the `CI` environment variable is set, a missing snapshot fails instead. To rewrite snapshots after an intended change:

```bash
TUINK_UPDATE_SNAPSHOTS=1 vendor/bin/behat
```

### Choosing the driver

Tag a feature or scenario `@in_process` or `@process`; a scenario tag wins over a feature tag. To run the whole suite on the other driver, add a profile; it inherits the default profile's settings:

```php
->withProfile((new Profile('process'))
    ->withExtension(new Extension(TuinkExtension::class, ['default_driver' => 'process'])))
```

```bash
vendor/bin/behat --profile=process
```

## Your own steps

Ask for the `Tuink` manager in the constructor:

```php
use Janalis\Tuink\Tuink;

final class StatusContext implements Behat\Behat\Context\Context
{
    public function __construct(private readonly Tuink $tuink) {}

    #[Behat\Step\Then('the status line should say :text')]
    public function statusLine(string $text): void
    {
        $this->tuink->getSession()->assertLineContains(2, $text);
    }
}
```

Or implement `TuinkAwareContextInterface` (or use `TuinkAwareTrait`):

```php
use Behat\Step\Then;
use Janalis\Tuink\Context\TuinkAwareContextInterface;
use Janalis\Tuink\Context\TuinkAwareTrait;

final class EditorContext implements TuinkAwareContextInterface
{
    use TuinkAwareTrait;

    #[Then('the status bar should say :text')]
    public function statusBar(string $text): void
    {
        $session = $this->getSession();
        $session->waitUntil(
            static fn () => str_contains($session->getLine($session->getRows()), $text),
            sprintf('The status bar does not say "%s".', $text),
        );
    }
}
```

`Session` also has `type()`, `press()`, `send()`, `paste()`, `resize()`, `settle()`, `getScreenText()`, `getLines()`, `getLine()`, `getRegion()`, `snapshot()`, `find()`, `findOne()`, `dump()` and the `assert*()` methods behind the steps. On the `in_process` driver, `$session->getDriver()->getTui()` returns the application's `Tui`.

## Gotchas

- A change made outside input handling and ticks, in a timer or a promise callback, needs `$tui->requestRender()` to reach the screen. tuink shows exactly what the terminal would, so a missing request shows up as a step that times out.
- The `in_process` driver shares the Revolt event loop with Behat. When the application stops, the callbacks it registered on the loop are cancelled, so its timers cannot fire in the next scenario.
- The `process` driver reads the screen through `ScreenBuffer`, which understands what Symfony Tui writes. Programs that rely on other escape sequences (alternate screen, scroll regions) may not be decoded faithfully.

## Developing

```bash
make install
make verify      # every gate CI runs
```

| Command          | Gate                                                              |
|------------------|-------------------------------------------------------------------|
| `make fmt-check` | mago formatting (`make fmt` applies it)                           |
| `make lint`      | mago lint, every finding fatal                                    |
| `make analyze`   | mago static analysis, every finding fatal                         |
| `make test`      | PHPUnit                                                           |
| `make behat`     | Behat on both drivers                                             |
| `make infection` | Mutation testing with pcov: every mutant must die (100% MSI)      |

`example/` holds the demo app the self-tests drive.

## Contributing

Contributions are welcome: read [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities as
described in [SECURITY.md](SECURITY.md). Changes are recorded in [CHANGELOG.md](CHANGELOG.md),
which follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## License

MIT, see [LICENSE](LICENSE).
