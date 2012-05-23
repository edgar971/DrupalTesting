<?php

namespace Liip\Drupal\Testing\Test;

use Liip\Drupal\Testing\Helper\DrupalConnector;

use Symfony\Component\DomCrawler\Crawler;

use Monolog\Logger;

class DrupalTestCase extends WebTestCase
{
    protected $connector;

    protected $baseUrl;

    public function __construct($baseUrl)
    {
        parent::__construct();

        $this->baseUrl = $baseUrl;
        $this->connector = new DrupalConnector();
        $this->connector->bootstrapDrupal();
    }

    /**
     * Log in to Drupal
     * @param string $user
     * @param string $pass
     * @param bool $expectedToFail Set this to true if you expect the credentials to be wrong
     * @return void
     */
    protected function drupalLogin($user, $pass, $expectedToFail = false)
    {
        $crawler = $this->getCrawler($this->baseUrl . '/user');
        $this->assertResponseStatusEquals(200);

        $form = $crawler->selectButton('Log in')->form();
        $this->submitForm($form, array('name' => $user, 'pass' => $pass));

        $crawler = $this->getCrawler($this->baseUrl . '/user');
        $this->assertResponseStatusEquals(200);

        $list = $crawler->filterXPath('//a[@href="/user/logout"]');
        if (!$expectedToFail) {
            $this->assertTrue(count($list) > 0, sprintf("Login failed for user %s, pass %s", $user, $pass));
            $this->log(sprintf('User %name successfully logged in.', $user->name), Logger::INFO);
        } else {
            $this->assertTrue(count($list) === 0, sprintf("Login succeeded but was expected to fail for user %s, pass %s", $user, $pass));
        }
    }

    /**
     * Logout from Drupal
     * @return void
     */
    protected function drupalLogout()
    {
        $this->getCrawler($this->baseUrl . '/user/logout');
    }

    /**
     * Create a user with a given set of permissions.
     *
     * (from simpletest)
     *
     * @param string $name
     * @param string $email
     * @param string $pass
     * @param array $permissions
     *   Array of permission names to assign to user. Note that the user always
     *   has the default permissions derived from the "authenticated users" role.
     * @return object|false
     *   A fully loaded user object with pass_raw property, or FALSE if account
     *   creation fails.
     */
    protected function drupalCreateUser($name = null, $email = null, $pass = null, array $permissions = array())
    {

        // Create a role with the given permission set, if any.
        $rid = FALSE;
        if ($permissions) {
            $rid = $this->drupalCreateRole($permissions);
            if (!$rid) {
                return FALSE;
            }
        }

        // Create a user assigned to that role.
        $edit = array();
        $edit['name'] = is_null($name) ? uniqid('test_user_') : $name;
        $edit['mail'] = is_null($email) ? $edit['name'] . '@test.com' : $email;
        $edit['pass'] = is_null($pass) ? $this->connector->user_password() : $pass;
        $edit['status'] = 1;
        if ($rid) {
            $edit['roles'] = array($rid => $rid);
        }

        $account = $this->connector->user_save($this->connector->drupal_anonymous_user(), $edit);

        $this->assertTrue(!empty($account->uid), sprintf('Could not create user %s', $name));
        $this->log(sprintf('User created with name %s and pass %s', $name, $pass), Logger::INFO);

        // Add the raw password so that we can log in as this user.
        $account->pass_raw = $edit['pass'];
        return $account;
    }

    /**
     * Delete a Drupal user
     * @param $account
     * @return void
     */
    protected function drupalDeleteUser($account)
    {
        $this->connector->user_delete($account->uid);
    }

    /**
     * Internal helper function; Create a role with specified permissions.
     *
     * @param array $permissions Array of permission names to assign to role.
     *   Array of permission names to assign to role.
     * @param $name
     *   (optional) String for the name of the role.  Defaults to a random string.
     * @return mixed Role ID of newly created role, or FALSE if role creation failed.
     */
    protected function drupalCreateRole(array $permissions, $name = NULL)
    {
        // Generate random name if it was not passed.
        if (!$name) {
            $name = uniqid('role_');
        }

        // Check the all the permissions strings are valid.
        if (!$this->assertValidPermissions($permissions)) {
            return FALSE;
        }

        // Create new role.
        $role = new stdClass();
        $role->name = $name;
        $this->connector->user_role_save($role);
        $this->connector->user_role_grant_permissions($role->rid, $permissions);

        $this->assertTrue(isset($role->rid), sprintf('Could not create role %s', $name));
        $this->log(sprintf("Created role '%s', RID = %s", $name, $role->rid), Logger::INFO);

        if ($role && !empty($role->rid)) {
            
            $count = $this->connector->db_query(
                'SELECT COUNT(*) FROM {role_permission} WHERE rid = :rid',
                array(':rid' => $role->rid)
            )->fetchField();
            
            $this->assertTrue($count == count($permissions), sprintf('The permissions count for %s does not match', $name));
            $this->log(sprintf('Created permissions: %s', implode(', ', $permissions)), Logger::INFO);

            return $role->rid;
        }
        else {
            return FALSE;
        }
    }

    // ----- ASSERTIONS -------------------------------------------------------

    /**
     * Check to make sure that the array of permissions are valid.
     *
     * (from simpletest)
     *
     * @param array $permissions Permissions to check.
     * @param bool $reset Reset cached available permissions.
     * @return bool TRUE or FALSE depending on whether the permissions are valid.
     */
    protected function assertValidPermissions(array $permissions, $reset = FALSE)
    {
        $available = &$this->connector->drupal_static(__FUNCTION__);

        if (!isset($available) || $reset) {
            $available = array_keys($this->connector->module_invoke_all('permission'));
        }

        $valid = TRUE;
        foreach ($permissions as $permission) {
            if (!in_array($permission, $available)) {
                $this->fail(sprintf('Invalid permission %permission.', $permission));
                $valid = FALSE;
            }
        }
        return $valid;
    }

    protected function assertCanLogin($user, $pass)
    {
        $this->drupalLogin($user, $pass);
        $this->drupalLogout();
    }

    protected function assertCannotLogin($user, $pass)
    {
        $this->drupalLogin($user, $pass, true);
    }

}