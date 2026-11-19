Feature: Failures show the screen

  Scenario: Missing text
    Given I start the app
    Then I should see "Goodbye"

  @process
  Scenario: Selectors on a process need the probe
    Given I start the app
    Then the widget "#name" should be focused
