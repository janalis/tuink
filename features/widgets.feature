@in_process
Feature: Looking inside the application
  Widget steps read the Tui itself, so they need it in the same process.

  Scenario: Focus moves on submit
    Given I start the app
    Then the widget "name" should be focused
    When I type "Ada"
    Then the widget "name" should have the value "Ada"
    When I press "Enter"
    Then the widget "color" should be focused

  Scenario: Focusing a widget
    Given I start the app
    When I focus the widget "color"
    And I press "Down"
    And I press "Enter"
    Then I should see "You picked Green"
