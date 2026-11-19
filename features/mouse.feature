Feature: Clicking, scrolling and dragging
  Steps send SGR mouse reports, as a terminal does in mode 1006; the demo
  hit-tests them against where its widgets were drawn. Steps that name a
  widget find it with a CSS selector; the others need no help from the app.

  Background:
    Given I start the app

  Scenario: Clicking a widget
    When I click "#color"
    Then I should see "You picked Green"
    And the element "#color" should be focused

  Scenario: Clicking text on the screen
    When I click on the text "Blue"
    Then I should see "You picked Blue"

  Scenario: Clicking a cell
    When I click at line 5, column 4
    Then I should see "You picked Red"

  Scenario: Other buttons
    When I right-click at line 3, column 7
    Then I should see "Right click at 3:7"
    When I middle-click "#name"
    Then I should not see "You picked"

  Scenario: Modifiers
    When I click on the text "Red" with "Ctrl"
    Then I should see "You picked Red with Ctrl"

  Scenario: Double click
    When I type "Ada"
    And I click on the text "Blue"
    And I double-click "#name"
    Then I should see "Hello, Ada!"
    When I triple-click "#name"
    Then the element "#color" should be focused

  Scenario: Scrolling
    When I scroll down 2 times over "#color"
    Then line 7 should be "→ Blue"
    When I scroll up at line 6, column 3
    Then line 6 should be "→ Green"
    And I should not see "You picked"

  Scenario: Dragging
    When I drag from line 2, column 1 to line 3, column 5
    Then I should see "Dragged to 3:5"
    When I drag "#status" to "#color"
    Then I should see "Dragged to 6:40"
