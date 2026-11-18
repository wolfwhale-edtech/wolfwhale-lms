@core @core_course
Feature: Restrict activities availability
  In order to prevent the use of some activities
  As an admin
  I need to control which activities can be used in courses

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | 1 | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category | format |
      | Course 1 | C1 | 0 | topics |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
    And the following "activities" exist:
      | activity | course | name             |
      | assign   | C1     | Test assign name |
  Scenario: Activities can be added with the default permissions
    Given I log in as "teacher1"
    When I add a page activity to course "Course 1" section "1" and I fill the form with:
      | Name | Test page name |
      | Description | Test page description |
    Then I should see "Test page name"
    And I should see "Test assign name"

  @javascript
  Scenario Outline: Activities can not be added when the admin restricts the permissions
    Given the following "role capability" exists:
      | role           | editingteacher  |
      | <activityrole> | prohibit        |
    And I log in as "teacher1"
    When I am on "Course 1" course homepage with editing mode on
    And I open the activity chooser
    Then "Add a new <activitytype>" "link" should not exist in the "Add an activity or resource" "dialogue"

    Examples:
      | activityrole                    | activitytype         |
      | mod/assign:addinstance          | Assignment           |
      | mod/book:addinstance            | Book                 |
      | mod/folder:addinstance          | Folder               |
      | mod/forum:addinstance           | Forum                |
      | mod/h5pactivity:addinstance     | H5P                  |
      | mod/label:addinstance           | Text and media area  |
      | mod/page:addinstance            | Page                 |
      | mod/quiz:addinstance            | Quiz                 |
      | mod/resource:addinstance        | File                 |
      | mod/url:addinstance             | URL                  |
