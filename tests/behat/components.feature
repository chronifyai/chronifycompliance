@local @local_chronifycompliance @javascript
Feature: Shared page components
  In order to trust what the pages show
  As an administrator
  I need to see how old the data is, refresh it, and hide invitations I do not need

  Scenario: Every component renders in the gallery
    Given I log in as "admin"
    When I visit "/local/chronifycompliance/components.php"
    Then I should see "Component gallery"
    And I should see "Dashboard" in the ".local-chronifycompliance-nav" "css_element"
    And I should see "No data yet."
    And I should see "Refreshing now."
    And I should see "The last refresh failed"
    And I should see "Pass grade missing"
    And I should see "Grade to pass set to 7 out of 10."

  Scenario: Refresh now queues a refresh
    Given I log in as "admin"
    And I visit "/local/chronifycompliance/components.php"
    When I click on "Refresh now" "button" in the "[data-dataset='activity']" "css_element"
    Then I should see "Refresh queued." in the "[data-dataset='activity']" "css_element"
    And I reload the page
    And I should see "Refreshing now." in the "[data-dataset='activity']" "css_element"

  Scenario: Dismissed invitations stay hidden
    Given I log in as "admin"
    And I visit "/local/chronifycompliance/components.php"
    And I should see "Want an expert to review your results?"
    When I click on "Hide for 30 days" "button"
    Then I should not see "Want an expert to review your results?"
    And I reload the page
    And I should not see "Want an expert to review your results?"
    And I should see "None" in the "[data-region='invitation-slot']" "css_element"

  Scenario: The administrator can turn invitations off
    Given the following config values are set as admin:
      | invitations | 0 | local_chronifycompliance |
    And I log in as "admin"
    When I visit "/local/chronifycompliance/components.php"
    Then I should not see "Want an expert to review your results?"
