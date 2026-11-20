<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Driver\InProcessDriver;
use Janalis\Tuink\Example\DemoApp;
use Janalis\Tuink\Exception\ExpectationException;
use Janalis\Tuink\Selector\Element;
use Janalis\Tuink\Selector\WidgetTree;
use Janalis\Tuink\Session;
use Janalis\Tuink\Tests\Fixtures\Widget\ValueWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class WidgetQueryTest extends TestCase
{
    private ?Session $session = null;

    protected function tearDown(): void
    {
        $this->session?->stop();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'suffix dropped' => [TextWidget::class, 'text'];
        yield 'kebab case' => [SelectListWidget::class, 'select-list'];
        yield 'container' => [ContainerWidget::class, 'container'];
        yield 'no suffix' => ['App\Ui\FileTree', 'file-tree'];
        yield 'acronym' => ['App\Ui\HTMLView', 'html-view'];
        yield 'global class' => ['Sidebar', 'sidebar'];
        yield 'only the suffix' => ['App\Widget', 'widget'];
    }

    #[DataProvider('names')]
    public function testElementName(string $class, string $name): void
    {
        $this->assertSame($name, WidgetTree::elementName($class));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function selectors(): iterable
    {
        yield 'type' => ['input', ['input#name']];
        yield 'id' => ['#color', ['select-list#color']];
        yield 'class' => ['.message', ['text#greeting.message', 'text#choice.message']];
        yield 'type and class' => ['text.title', ['text.title']];
        yield 'child' => ['#form > *', ['input#name', 'text#greeting.message', 'select-list#color']];
        yield 'descendant' => [':root .message', ['text#greeting.message', 'text#choice.message']];
        yield 'sibling' => ['input + text', ['text#greeting.message']];
        yield 'not' => ['#form > :not(input)', ['text#greeting.message', 'select-list#color']];
        yield 'focus' => [':focus', ['input#name']];
        yield 'visible' => ['.message:visible', []];
        yield 'visible and drawn' => ['text:visible', ['text.title', 'text#status', 'text#size', 'text']];
        yield 'contains' => ["text:contains('Status')", ['text#status']];
        yield 'contains through children' => ["container:contains('Blue')", ['container', 'container#form']];
        yield 'attribute' => ['[value=""]', ['input#name']];
        yield 'widget class' => ['[widget$="SelectListWidget"]', ['select-list#color']];
        yield 'root' => [':root', ['container']];
        yield 'nth' => ['#form > :nth-child(2)', ['text#greeting.message']];
        yield 'none' => ['editor', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('selectors')]
    public function testSelect(string $selector, array $expected): void
    {
        $found = $this->demo()->find($selector);

        $this->assertSame($expected, \array_map(static fn (Element $e): string => $e->describe(), $found));
    }

    public function testElementsCarryWhatTheyShow(): void
    {
        $session = $this->demo();
        $session->send('Ada<Enter>');

        [$greeting] = $session->find('#greeting');
        $this->assertTrue($greeting->isVisible());
        $this->assertSame('Hello, Ada!', $greeting->text);
        $this->assertSame(['input#name'], \array_map(static fn (Element $e): string => $e->describe(), $session->find('input[value="Ada"]')));
        $this->assertSame(['select-list#color'], \array_map(static fn (Element $e): string => $e->describe(), $session->find('select-list:focus')));
        $this->assertSame("→ Red\n  Green\n  Blue", $session->find('#color')[0]->text);
    }

    public function testInvalidSelector(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid CSS selector "input[":');

        $this->demo()->find('input[');
    }

    public function testUnknownPseudoClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Pseudo-class "hover" not supported.');

        $this->demo()->find('input:hover');
    }

    public function testTheTreeSurvivesXml(): void
    {
        $terminal = new VirtualTerminal(40, 12);
        $tui = new DemoApp($terminal)->tui;
        $tui->start();
        $tui->processRender();

        $tree = WidgetTree::fromTui($tui);
        $copy = WidgetTree::fromXml($tree->toXml());
        $tui->stop();

        $this->assertSame($tree->toXml(), $copy->toXml());
        $this->assertCount(\count($tree->widgets), $copy->document->getElementsByTagName('*'));
        // Widgets do not travel.
        $this->assertSame([], $copy->widgets);
    }

    public function testTheRootComesFromGetRootWhenTuiHasIt(): void
    {
        $other = new ContainerWidget()->setId('elsewhere');
        $other->add(new TextWidget('x')->setId('inside'));
        $tui = new class($other, new VirtualTerminal()) extends Tui {
            public function __construct(
                private readonly ContainerWidget $other,
                VirtualTerminal $terminal,
            ) {
                parent::__construct(terminal: $terminal);
            }

            public function getRoot(): ContainerWidget
            {
                return $this->other;
            }
        };

        $root = WidgetTree::fromTui($tui)->document->documentElement;

        $this->assertSame('elsewhere', $root?->getAttribute('id'));
    }

    public function testInvalidXml(): void
    {
        $internal = \libxml_use_internal_errors(true);

        try {
            WidgetTree::fromXml('<container');
            $this->fail('An unexpected value exception was expected.');
        } catch (\UnexpectedValueException $e) {
            $this->assertSame('The widget tree is not valid XML.', $e->getMessage());
            // The parse errors do not linger for whoever reads libxml's errors next
            $this->assertSame([], \libxml_get_errors());
        } finally {
            \libxml_use_internal_errors($internal);
        }
    }

    /**
     * @return iterable<string, array{\Closure(Session): mixed, string}>
     */
    public static function failures(): iterable
    {
        yield 'see in, nothing matches' => [static fn (Session $s) => $s->assertSeeIn('editor', text: 'x'), 'The text "x" was not found in "editor": no element matches it.'];
        yield 'see in, wrong text' => [
            static fn (Session $s) => $s->assertSeeIn('#status', text: 'Goodbye'),
            "The text \"Goodbye\" was not found in \"#status\". It shows:\ntext#status:\n1 | Status: loading",
        ];
        yield 'see in, not drawn' => [static fn (Session $s) => $s->assertSeeIn('#greeting', text: 'Hello'), "It shows:\ntext#greeting.message (not drawn)"];
        yield 'not see in' => [static fn (Session $s) => $s->assertNotSeeIn('#color', text: 'Red'), "The text \"Red\" was found in \"#color\":\nselect-list#color:\n1 | → Red"];
        yield 'count' => [static fn (Session $s) => $s->assertElementCount('.message', count: 3), '2 elements match ".message", not 3: text#greeting.message, text#choice.message.'];
        yield 'count none' => [static fn (Session $s) => $s->assertElementCount('editor', count: 1), 'No element matches "editor", not 1.'];
        yield 'one, none' => [static fn (Session $s) => $s->findOne('editor'), 'No element matches "editor", one was expected.'];
        yield 'one, many' => [static fn (Session $s) => $s->findOne('.message'), '2 elements match ".message", one was expected: text#greeting.message, text#choice.message.'];
        yield 'visible' => [static fn (Session $s) => $s->assertElementVisible('#greeting'), 'No element matching "#greeting" is on the screen.'];
        yield 'not visible' => [static fn (Session $s) => $s->assertElementNotVisible('input'), 'Elements matching "input" are on the screen: input#name.'];
        yield 'shows, many' => [
            static fn (Session $s) => $s->assertElementShows('.message', expected: 'x'),
            '2 elements match ".message", one was expected: text#greeting.message, text#choice.message.',
        ];
        yield 'field contains' => [static fn (Session $s) => $s->assertFieldContains('#name', text: 'Ada'), 'The field "#name" has the value "", which does not contain "Ada".'];
        yield 'see count' => [static fn (Session $s) => $s->assertSeeCount('Blue', count: 3), 'The text "Blue" is on the screen 1 times, not 3.'];
        yield 'not see match' => [static fn (Session $s) => $s->assertNotSeeMatch('/Size: \\d+/'), 'Text on the screen matches /Size: \\d+/.'];
        yield 'line not contains' => [static fn (Session $s) => $s->assertLineNotContains(1, text: 'demo'), 'Line 1 contains "demo".'];
        yield 'style' => [static fn (Session $s) => $s->assertTextStyle('Tuink demo', attribute: 'italic'), 'The text "Tuink demo" is not italic: bold, #5f87ff.'];
        yield 'style absent' => [static fn (Session $s) => $s->assertTextWithoutStyle('Tuink demo', attribute: 'bold'), 'The text "Tuink demo" is bold: bold, #5f87ff.'];
        yield 'color' => [static fn (Session $s) => $s->assertTextColor('Status', color: 'red'), 'The text "Status" does not have the foreground color red: plain.'];
        yield 'background' => [static fn (Session $s) => $s->assertTextBackground('Tuink', color: '#000'), 'The text "Tuink" does not have the background color #000000: bold, #5f87ff.'];
        yield 'bell' => [static fn (Session $s) => $s->assertBell(), 'The bell rang 0 times.'];
        yield 'bell count' => [static fn (Session $s) => $s->assertBell(2), 'The bell rang 0 times, not 2.'];
        yield 'title' => [static fn (Session $s) => $s->assertTitle('Other'), 'The title is "Tuink demo", not "Other".'];
        yield 'select missing' => [static fn (Session $s) => $s->selectOption('#color', label: 'Purple'), 'The list "#color" has no item "Purple"; its items are "Red", "Green", "Blue".'];
        yield 'shows, wrong' => [static fn (Session $s) => $s->assertElementShows('#size', expected: 'Size: 1x1'), "The element \"#size\" does not show:\n1 | Size: 1x1\n\nIt shows:\n1 | Size: 80x24"];
    }

    /**
     * Messages describe the state at the timeout, not when the wait began.
     *
     * @param \Closure(Session): mixed $assertion
     */
    #[DataProvider('failures')]
    public function testFailureMessages(\Closure $assertion, string $message): void
    {
        $session = $this->demo(timeout: 0.05);

        try {
            $assertion($session);
            $this->fail('An expectation exception was expected.');
        } catch (ExpectationException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertStringContainsString('Screen (80x24, in_process driver, running):', $e->getMessage());
        }
    }

    public function testAFieldWithoutAValue(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The element "#color" (Symfony\Component\Tui\Widget\SelectListWidget) has no value.');

        $this->demo(timeout: 0.05)->assertFieldContains(selector: '#color', text: 'x');
    }

    public function testPassingAssertions(): void
    {
        $session = $this->demo();
        $session->assertSeeIn('#form', text: 'Name:');
        $session->assertNotSeeIn('#status', text: 'ready');
        $session->assertElementCount('#form > *', count: 3);
        $session->assertElementVisible('text.title');
        $session->assertElementNotVisible('.message');
        $session->assertElementShows('text.title', expected: 'Tuink demo');

        // In process, elements carry their widget.
        $this->assertSame('name', $session->findOne('input')->widget?->getId());
    }

    /**
     * @return iterable<string, array{mixed, mixed, ?string, ?string}>
     */
    public static function customWidgets(): iterable
    {
        yield 'string value' => ['Ada', null, 'Ada', null];
        yield 'int value' => [5, null, '5', null];
        yield 'float value' => [1.5, null, '1.5', null];
        yield 'bool value' => [true, null, null, null];
        yield 'array value' => [['Ada'], null, null, null];
        yield 'selected label' => [null, ['label' => 'Red'], null, 'Red'];
        yield 'label that is not a string' => [null, ['label' => 7], null, null];
        yield 'item that is not an array' => [null, 'Red', null, null];
    }

    #[DataProvider('customWidgets')]
    public function testValuesOfCustomWidgets(mixed $value, mixed $selectedItem, ?string $expectedValue, ?string $expectedSelected): void
    {
        $this->session = new Session(
            new InProcessDriver(static function (VirtualTerminal $terminal) use ($value, $selectedItem): Tui {
                $tui = new Tui(terminal: $terminal);
                $tui->add(new ValueWidget($value, $selectedItem)->setId('custom'));

                return $tui;
            }),
            timeout: 0.1,
            quietPeriod: 0.02,
        );
        $this->session->start();
        $element = $this->session->findOne('#custom');

        $this->assertSame('value', $element->name);
        $this->assertSame($expectedValue, $element->value);
        $this->assertSame($expectedSelected, $element->selected);
    }

    public function testAWidgetDrawnAwayFromTheLeftEdge(): void
    {
        $this->session = new Session(
            new InProcessDriver(static function (VirtualTerminal $terminal): Tui {
                $tui = new Tui(terminal: $terminal);
                $row = new ContainerWidget()->setStyle(new Style(direction: Direction::Horizontal));
                $row->add(new TextWidget('left')->setId('left'));
                $row->add(new TextWidget('right')->setId('right'));
                $tui->add($row);

                return $tui;
            }),
            40,
            5,
            0.1,
            0.02,
        );
        $this->session->start();
        $right = $this->session->findOne('#right');

        $this->assertGreaterThan(0, $right->rect?->col);
        $this->assertSame('right', $right->text);
    }

    public function testLabels(): void
    {
        $this->session = new Session(
            new InProcessDriver(static function (VirtualTerminal $terminal): Tui {
                $tui = new Tui(terminal: $terminal);
                $tui->add(
                    new TextWidget('labelled')
                        ->setId('with')
                        ->setLabel('Path'),
                );
                $tui->add(new TextWidget('bare')->setId('without'));

                return $tui;
            }),
            timeout: 0.1,
            quietPeriod: 0.02,
        );
        $this->session->start();

        $this->assertSame('Path', $this->session->findOne('#with')->label);
        $this->assertNull($this->session->findOne('#without')->label);
        $this->assertSame(['text#with'], \array_map(static fn (Element $e): string => $e->describe(), $this->session->find('[label="Path"]')));
    }

    private function demo(float $timeout = 1.0): Session
    {
        $this->session = new Session(
            new InProcessDriver(static fn (VirtualTerminal $terminal): Tui => new DemoApp($terminal)->tui),
            timeout: $timeout,
            quietPeriod: 0.02,
        );
        $this->session->start();

        return $this->session;
    }
}
