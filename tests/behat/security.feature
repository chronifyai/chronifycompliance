@local @local_chronifycompliance @javascript
Feature: Administrators review and fix the security of the site
  In order to know whether my Moodle is safe right now
  As an administrator
  I need every security problem grouped by area, with one action each, and the checks that passed

  Background:
    # Moodle's own security checks find different things on each test machine, so they are off here.
    Given the following config values are set as admin:
      | config               | value | plugin                   |
      | enabled_coresecurity | 0     | local_chronifycompliance |
    And the Chronify.Compliance demo site problem "webservices" exists
    And the Chronify.Compliance demo site problem "adminmfa" exists

  Scenario: Problems grouped by area, passed checks and filters
    When I am on the "local_chronifycompliance > Security" page logged in as "admin"
    Then I should see "Security and Compliance"
    And I should see "Multi-factor authentication is off" in the "[data-group='access']" "css_element"
    And I should see "Web services are on, but nothing uses them" in the "[data-group='config']" "css_element"
    And I should see "Passed checks"
    When I click on "[data-filter='critical']" "css_element"
    Then I should see "Multi-factor authentication is off"
    And I should not see "Web services are on, but nothing uses them"
    And I should not see "Passed checks"
    When I click on "Show all" "link"
    Then I should see "Web services are on, but nothing uses them"

  Scenario: Preview, apply and undo a one click security fix
    Given I log in as "admin"
    When I visit "/local/chronifycompliance/security.php?group=config"
    And I click on "Preview fix" "button" in the "Web services on without need" "local_chronifycompliance > Finding"
    Then I should see "Enable web services" in the "Preview fix" "dialogue"
    And I should see "This changes a site setting for everyone." in the "Preview fix" "dialogue"
    When I click on "Apply fix" "button" in the "Preview fix" "dialogue"
    Then "[data-region='undo-toast']" "css_element" should be visible
    When I press "Check again"
    Then I should see "The site checks ran."
    And I should not see "Web services are on, but nothing uses them"
    When I click on "[data-filter='healthy']" "css_element"
    Then I should see "Web services are off or in use" in the "[data-group='passed']" "css_element"

  Scenario: Undo a security fix from the toast
    Given I log in as "admin"
    When I visit "/local/chronifycompliance/security.php?group=config"
    And I click on "Preview fix" "button" in the "Web services on without need" "local_chronifycompliance > Finding"
    And I click on "Apply fix" "button" in the "Preview fix" "dialogue"
    And I click on "Undo fix" "button" in the "[data-region='undo-toast']" "css_element"
    And I press "Check again"
    Then I should see "Web services are on, but nothing uses them"

  Scenario: The dashboard Security tile opens the Security view
    When I am on the "local_chronifycompliance > Dashboard" page logged in as "admin"
    And I click on "[data-pack='security']" "css_element"
    Then I should see "Passed checks"
