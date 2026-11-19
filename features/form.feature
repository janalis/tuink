Feature: Filling the demo form
  The same scenarios run on both drivers: in process by default,
  in a pseudo-terminal with the "process" profile.

  Background:
    Given I start the app

  Scenario: Typing a name and submitting it
    When I type "Ada"
    Then line 4 should be "Name: Ada"
    When I press "Enter"
    Then I should see "Hello, Ada!"

  Scenario: Sending characters and keys in one go
    When I send "Grace<Enter>"
    Then I should see "Hello, Grace!"

  Scenario: Pasting
    When I paste "Linus"
    And I press "Enter"
    Then I should see "Hello, Linus!"

  Scenario: Pasting a block
    When I paste:
      """
      Margaret
      """
    Then line 4 should be "Name: Margaret"

  Scenario: Editing with keys
    When I type "Adda"
    And I press "Left"
    And I press "Backspace"
    And I press "Enter"
    Then I should see "Hello, Ada!"

  Scenario: Picking a color
    When I press "Enter"
    And I press "Down" 2 times
    And I press "Enter"
    Then I should see "You picked Blue"
    And I should not see "You picked Red"
