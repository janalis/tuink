Feature: Frames, layout and what the application tells the terminal

  Scenario: Reading inside a frame
    Given I start the app
    Then I should not see the dialog "Help"
    When I press "F1"
    Then I should see the dialog "Help"
    And I should see "copy name" in the dialog "Help"
    And I should not see "Status" in the box "Help"
    And the panel "Help" should show:
      """
       Ctrl+S  save name
       Ctrl+Y  copy name
       F1      close help
      """
    When I click on the text "close help" in the frame "Help"
    Then I should not see the window "Help"

  Scenario: Where things are, relative to each other
    Given I start the app
    Then I should see "Tuink demo" above "Name:"
    And I should see "Blue" below "Red"
    And I should see "Enter: submit" before "Esc: leave"
    And I should see "Esc: leave" after "Enter: submit"

  Scenario: Copying to the clipboard
    Given I start the app
    Then nothing should have been copied to the clipboard
    When I type "Ada"
    And I press "Ctrl+Y"
    Then the clipboard should contain "Ada"
    And the clipboard should be:
      """
      Ada
      """
    When I press "F1"
    And I type "da"
    And I click on the text "copy name" in the dialog "Help"
    Then the clipboard should be "Adada"

  Scenario: The modes the application turned on
    Given I start the app
    Then the "mouse clicks" mode should be on
    And the "mouse drags" mode should be on
    And the "SGR mouse" mode should be on
    And the "focus events" mode should be on
    And the "mouse motion" mode should be off
    And the "alternate screen" mode should be off

  @process
  Scenario: Bracketed paste, which the real terminal turns on
    Given I start the app
    Then the "bracketed paste" mode should be on

  Scenario: The cursor follows the focused field
    Given I start the app
    Then the cursor should be visible
    And the cursor should be a blinking block
    And the cursor should be at line 4, column 7
    When I type "Ada"
    Then the cursor should be at line 4, column 10
    When I press "Enter"
    Then the cursor should be hidden

  Scenario: The terminal window's focus
    Given I start the app
    When the terminal loses focus
    Then I should see "Window unfocused"
    When the terminal gains the focus
    Then I should see "Window focused"

  Scenario: Scrolling with a modifier
    Given I start the app
    When I scroll down 2 times over "#color" with "Ctrl"
    Then line 7 should be "→ Blue"
    When I scroll up at line 6, column 3 with "Shift+Alt"
    Then line 6 should be "→ Green"
