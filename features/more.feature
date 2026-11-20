Feature: Forms, counts, negations, styles and the terminal
  Steps in the spirit of MinkContext, and steps for what only a terminal has.

  Background:
    Given I start the app
    And I should see "Status: ready"

  Scenario: Filling in a field
    When I fill in "#name" with "Ada"
    Then the "#name" field should contain "Ada"
    When I fill in "#name" with "Grace"
    Then the "#name" field should contain "Grace"
    And the "#name" field should not contain "Ada"

  Scenario: Filling in from a table
    When I fill in the following:
      | #name | Linus |
    Then line 4 should be "Name: Linus"

  Scenario: Selecting from a list
    When I select "Blue" from "#color"
    Then I should see "You picked Blue"
    When I select "Red" from "#color"
    Then I should see "You picked Red"

  Scenario: Counting and negating
    Then I should see "Blue" 1 time
    When I select "Blue" from "#color"
    Then I should see "Blue" 2 times
    And I should not see text matching "/Hello, \w+/"
    And line 4 should not contain "Ada"
    And the element "#form" should contain "Green"
    And the element "#form" should not contain "Purple"
    And the element "#name" should not be focused

  Scenario: Hovering
    When I hover over "#color"
    Then I should see "Hover at 6:40"
    When I hover over the text "Blue"
    Then I should see "Hover at 7:3"
    When I hover at line 1, column 1
    Then I should see "Hover at 1:1"

  Scenario: Styles
    Then the text "Tuink demo" should be bold
    And the text "Tuink demo" should not be italic
    And the text "Tuink demo" should be colored "#5f87ff"
    And the text "Status" should not be bold
    And the text "Ctrl+C: quit" should have the background "#303030"

  Scenario: The window title
    Then the terminal title should be "Tuink demo"
    When I fill in "#name" with "Ada"
    And I press "Enter"
    Then the terminal title should be "Tuink demo: Ada"

  @in_process
  Scenario: The bell
    # On macOS, Symfony Tui's real terminal plays a sound instead of writing BEL, so only the in-process driver sees it there
    When I press "Ctrl+G"
    Then the terminal should have rung the bell
    When I press "Ctrl+G"
    Then the terminal should have rung the bell 2 times

  Scenario: Restarting
    When I fill in "#name" with "Ada"
    And I restart the app
    Then line 4 should be "Name:"

  Scenario: Keys without a legacy encoding
    When I type "Ada"
    And I press "Shift+Backspace"
    Then the "#name" field should contain "Ad"
