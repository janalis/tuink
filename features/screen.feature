Feature: Checking the screen

  Scenario: The whole screen
    Given I start the app in a 60x12 terminal
    And I should see "Status: ready"
    When I type "Ada"
    Then the screen should be:
      """
      Tuink demo
      Status: ready
      Size: 60x12
      Name: Ada
      → Red
        Green
        Blue
      Enter: submit  Esc: leave  Ctrl+C: quit
      """

  Scenario: Work that finishes later
    Given I start the app
    Then I should see "Status: loading"
    And I should see "Status: ready"
    And I should not see "Status: loading"

  Scenario: Matching text
    Given I start the app
    Then I should see text matching "/Size: \d+x\d+/"

  Scenario: Waiting
    Given I start the app
    When I wait 0.4 seconds
    Then line 2 should contain "ready"

  Scenario: A snapshot
    Given I start the app in a 50x10 terminal
    And I should see "Status: ready"
    When I send "Ada<Enter>"
    And I press "Down"
    Then the screen should match the snapshot "after-greeting"
