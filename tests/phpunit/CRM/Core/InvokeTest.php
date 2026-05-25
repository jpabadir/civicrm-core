<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 * @group headless
 */
class CRM_Core_InvokeTest extends CiviUnitTestCase {

  private mixed $originalMaintenanceMode;
  private array $originalPermissions;

  public function setUp(): void {
    parent::setUp();
    // Clear any pre-existing session status messages so assertions are clean.
    CRM_Core_Session::singleton()->getStatus(TRUE);
    $this->originalMaintenanceMode = \Civi::settings()->get('core_maintenance_mode');
    $this->originalPermissions = CRM_Core_Config::singleton()->userPermissionClass->permissions;
  }

  public function tearDown(): void {
    \Civi::settings()->set('core_maintenance_mode', $this->originalMaintenanceMode);
    CRM_Core_Config::singleton()->userPermissionClass->permissions = $this->originalPermissions;
    parent::tearDown();
  }

  /**
   * When not in maintenance mode, checkMaintenanceMode() is a no-op.
   */
  public function testCheckMaintenanceModePassesWhenOff(): void {
    \Civi::settings()->set('core_maintenance_mode', '0');
    CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
    $this->callCheckMaintenanceMode(['civicrm', 'contact', 'view']);
    $this->assertEmpty(CRM_Core_Session::singleton()->getStatus());
  }

  /**
   * Users with bypass permission proceed normally but still see the maintenance notice.
   */
  public function testCheckMaintenanceModePassesWithBypassPermission(): void {
    \Civi::settings()->set('core_maintenance_mode', '1');
    CRM_Core_Config::singleton()->userPermissionClass->permissions = ['administer CiviCRM system'];
    $this->callCheckMaintenanceMode(['civicrm', 'contact', 'view']);
    $statuses = CRM_Core_Session::singleton()->getStatus(TRUE);
    $this->assertNotEmpty($statuses);
    $this->assertStringContainsString('maintenance', strtolower($statuses[0]['text']));
  }

  /**
   * AJAX paths pass through untouched — the API4 AJAX handler gates those.
   */
  public function testCheckMaintenanceModeSkipsAjax(): void {
    \Civi::settings()->set('core_maintenance_mode', '1');
    CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
    $this->callCheckMaintenanceMode(['civicrm', 'ajax', 'api4', 'Contact', 'get']);
    $this->assertEmpty(CRM_Core_Session::singleton()->getStatus());
  }

  /**
   * job/execute paths bypass maintenance mode so scheduled jobs still run.
   */
  public function testCheckMaintenanceModeSkipsJobExecute(): void {
    \Civi::settings()->set('core_maintenance_mode', '1');
    CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
    $this->callCheckMaintenanceMode(['civicrm', 'job', 'execute']);
    $this->assertEmpty(CRM_Core_Session::singleton()->getStatus());
  }

  /**
   * Home and auth paths show the maintenance notice but remain accessible.
   */
  public function testCheckMaintenanceModeShowsNoticeOnLoginPaths(): void {
    \Civi::settings()->set('core_maintenance_mode', '1');
    CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
    $paths = ['civicrm/home', 'civicrm/login', 'civicrm/login/password', 'civicrm/mfa/totp'];
    foreach ($paths as $path) {
      CRM_Core_Session::singleton()->getStatus(TRUE);
      $this->callCheckMaintenanceMode(explode('/', $path));
      $statuses = CRM_Core_Session::singleton()->getStatus(TRUE);
      $this->assertNotEmpty($statuses, "Expected maintenance notice on $path");
      $this->assertStringContainsString('maintenance', strtolower($statuses[0]['text']));
    }
  }

  /**
   * Non-login paths trigger content substitution for users without bypass.
   */
  public function testCheckMaintenanceModeSubstitutesContentForNonBypassUser(): void {
    \Civi::settings()->set('core_maintenance_mode', '1');
    CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
    $this->expectException(CRM_Core_Exception_PrematureExitException::class);
    $this->callCheckMaintenanceMode(['civicrm', 'contact', 'view']);
  }

  /**
   * Helper: invoke the protected checkMaintenanceMode method via reflection.
   */
  private function callCheckMaintenanceMode(array $args): void {
    $ref = new ReflectionMethod(CRM_Core_Invoke::class, 'checkMaintenanceMode');
    $ref->setAccessible(TRUE);
    $ref->invoke(NULL, $args);
  }

  /**
   * Test that no php errors come up invoking dashboard url for non-admins
   * Motivation: This currently fails on php 7.4 because of IDS and magicquotes.
   */
  public function testInvokeDashboardForNonAdmin(): void {
    CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];

    $_SERVER['REQUEST_URI'] = 'civicrm/dashboard?reset=1';
    $_GET['q'] = 'civicrm/dashboard';

    $item = CRM_Core_Invoke::getItem(['civicrm/dashboard?reset=1']);
    ob_start();
    CRM_Core_Invoke::runItem($item);
    ob_end_clean();
  }

  /**
   * Test dashboard with something actually on it.
   */
  public function testInvokeDashboardWithGettingStartedDashlet(): void {
    $user_id = $this->createLoggedInUser();
    $this->callAPISuccess('DashboardContact', 'create', [
      'dashboard_id' => 2,
      'contact_id' => $user_id,
    ]);

    CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];

    $_SERVER['REQUEST_URI'] = 'civicrm/dashboard?reset=1';
    $_GET['q'] = 'civicrm/dashboard';

    $item = CRM_Core_Invoke::getItem(['civicrm/dashboard?reset=1']);
    ob_start();
    CRM_Core_Invoke::runItem($item);
    ob_end_clean();
  }

  public function testOpeningSearchBuilder(): void {
    $_SERVER['REQUEST_URI'] = 'civicrm/contact/search/builder?reset=1';
    $_GET['q'] = 'civicrm/contact/search/builder';
    $_GET['reset'] = 1;

    $item = CRM_Core_Invoke::getItem([$_GET['q']]);
    ob_start();
    CRM_Core_Invoke::runItem($item);
    $contents = ob_get_clean();

    unset($_GET['reset']);
    $this->assertMatchesRegularExpression('/form.+id="Builder" class="CRM_Contact_Form_Search_Builder/', $contents);
  }

  public function testContactSummary(): void {
    $cid = $this->individualCreate([
      'first_name' => 'ContactPage',
      'last_name' => 'Summary',
      'do_not_phone' => 1,
      'gender_id' => 'Male',
    ]);
    $_SERVER['REQUEST_URI'] = "civicrm/contact/view?cid={$cid}&reset=1";
    $_GET['q'] = 'civicrm/contact/view';
    $_GET['reset'] = $_REQUEST['reset'] = 1;
    $_GET['cid'] = $_REQUEST['cid'] = $cid;

    $item = CRM_Core_Invoke::getItem([$_GET['q']]);
    ob_start();
    CRM_Core_Invoke::runItem($item);
    $contents = ob_get_clean();

    unset($_GET['q'], $_REQUEST['q']);
    unset($_GET['reset'], $_REQUEST['reset']);
    unset($_GET['cid'], $_REQUEST['cid']);

    $this->assertStringContainsString("<div class=\"crm-content crm-contact_type_label\">\n      Individual\n    </div>", $contents);
    $this->assertStringContainsString("<div class=\"crm-content crm-contact-privacy_values font-red upper\">\n                  Do not phone<br/>                                                                                              </div>", $contents);
    $this->assertStringContainsString("<div class=\"crm-content crm-contact-gender_display\">Male</div>", $contents);
  }

}
