Feature: The terminal and the application's life

  Scenario: Resizing
    Given I start the app in a 70x20 terminal
    Then I should see "Size: 70x20"
    When I resize the terminal to 40x10
    Then I should see "Size: 40x10"
    And I should see "Tuink demo"

  Scenario: Quitting
    Given I start the app
    Then the app should be running
    When I press "Ctrl+C"
    Then the app should have exited

  @process
  Scenario: The exit code, which only a process has
    Given I start the app
    When I press "Enter"
    And I press "Escape"
    Then the app should have exited with code 2

  Scenario: Stopping it
    Given I start the app
    When I stop the app
    And I start the app
    Then I should see "Name:"
