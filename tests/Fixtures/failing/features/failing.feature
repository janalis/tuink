Feature: Failures show the screen

  Scenario: Missing text
    Given I start the app
    Then I should see "Goodbye"

  @process
  Scenario: Widget steps need the app in process
    Given I start the app
    Then the widget "name" should be focused
