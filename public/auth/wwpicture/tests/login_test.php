<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace auth_wwpicture;

/**
 * Picture codes and the school sign-in buttons.
 *
 * Google and Microsoft HTTP is mocked. No live sign-in is attempted.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(code::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(school::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(buttons::class)]
final class login_test extends \advanced_testcase {
    /**
     * The database stores a hash, not the pictures.
     */
    public function test_picture_code_is_stored_as_a_hash(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $picks = ['sun', 'tree', 'fish'];

        code::set((int) $user->id, $picks);

        $row = $DB->get_record('auth_wwpicture_code', ['userid' => $user->id], '*', MUST_EXIST);
        $this->assertNotSame('sun,tree,fish', $row->codehash);
        $this->assertNotSame(0, password_get_info($row->codehash)['algo']);
        $this->assertTrue(password_verify('sun,tree,fish', $row->codehash));
        $this->assertFalse(password_verify('sun,tree,star', $row->codehash));
    }

    /**
     * A wrong code fails, waits, and then locks. The right code does not open a session while locked.
     */
    public function test_wrong_code_fails_and_locks(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setUser(0);
        $clock = $this->mock_clock_with_frozen(1700000000);
        [$course, $token, $user] = $this->class_with_student();
        $right = ['sun', 'tree', 'fish'];
        code::set((int) $user->id, $right);

        $this->assertSame('bad', code::sign_in($course, $token, (int) $user->id, ['sun', 'tree', 'star']));
        $this->assertSame('limited', code::sign_in($course, $token, (int) $user->id, $right));
        $this->assertNotEquals($user->id, $USER->id ?? 0);

        for ($i = 1; $i < code::TRIES; $i++) {
            $clock->bump(code::GAP);
            $this->assertSame('bad', code::sign_in($course, $token, (int) $user->id, ['moon', 'star', 'fish']));
        }
        $clock->bump(code::GAP);
        $this->assertSame('locked', code::sign_in($course, $token, (int) $user->id, $right));
        $this->assertNotEquals($user->id, $USER->id ?? 0);

        $clock->bump(code::LOCK);
        $this->assertSame('ok', code::sign_in($course, $token, (int) $user->id, $right));
        $this->assertEquals($user->id, $USER->id);
    }

    /**
     * A student from another class cannot use this class page, even with their own code.
     */
    public function test_other_class_cannot_sign_in(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setUser(0);
        [$course, $token] = $this->class_with_student('North', 'Ada');
        $othercourse = $this->school_course('South');
        $other = $this->getDataGenerator()->create_user(['firstname' => 'Noah', 'lastname' => 'Moss']);
        $this->getDataGenerator()->enrol_user($other->id, $othercourse, 'student');
        $picks = ['moon', 'star', 'fish'];
        code::set((int) $other->id, $picks);

        $this->assertSame('denied', code::sign_in($course, $token, (int) $other->id, $picks));
        $this->assertNotEquals($other->id, $USER->id ?? 0);
        $row = $DB->get_record('auth_wwpicture_code', ['userid' => $other->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->failures);
    }

    /**
     * A school with picture sign-in off does not accept a code.
     */
    public function test_disabled_school_cannot_use_picture_sign_in(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setUser(0);
        \core\plugininfo\auth::enable_plugin('wwpicture', 1);
        $cat = $this->getDataGenerator()->create_category(['name' => 'North']);
        $course = $this->getDataGenerator()->create_course(['category' => $cat->id]);
        school::save((int) $cat->id, false, '');
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $token = code::token((int) $course->id);
        $picks = ['sun', 'tree', 'fish'];
        code::set((int) $user->id, $picks);

        $this->assertFalse(code::open((int) $course->id, $token));
        $this->assertSame('closed', code::sign_in((int) $course->id, $token, (int) $user->id, $picks));
        $this->assertNotEquals($user->id, $USER->id ?? 0);
    }

    /**
     * Google and Microsoft stay hidden until an issuer has a client id, a secret, and is enabled.
     */
    public function test_oauth_buttons_hidden_when_not_configured(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        \core\plugininfo\auth::enable_plugin('oauth2', 1);

        $this->assertSame([], buttons::oauth());
        $html = $this->login_html();
        $this->assertStringNotContainsString('Google', $html);
        $this->assertStringNotContainsString('Microsoft', $html);
        $this->assertStringNotContainsString('Moodle', $html);
        $this->assertStringContainsString('Picture', $html);

        \curl::mock_response($this->discovery());
        $issuer = \core\oauth2\api::create_standard_issuer('google');
        $this->assertSame([], buttons::oauth());

        $issuer->set('clientid', 'not-a-real-client');
        $issuer->set('clientsecret', 'not-a-real-secret');
        $issuer->update();
        $buttons = buttons::oauth();
        $this->assertArrayHasKey('google', $buttons);
        $this->assertArrayNotHasKey('microsoft', $buttons);
        $html = $this->login_html();
        $this->assertStringContainsString('Google', $html);
        $this->assertStringNotContainsString('Microsoft', $html);

        $issuer->set('enabled', 0);
        $issuer->update();
        $this->assertSame([], buttons::oauth());
        $this->assertStringNotContainsString('Google', $this->login_html());

        $issuer->set('enabled', 1);
        $issuer->set('clientid', '');
        $issuer->update();
        $this->assertSame([], buttons::oauth());
    }

    /**
     * A school domain blocks an OAuth email. The userinfo call is a mocked response.
     */
    public function test_school_domain_blocks_oauth_email(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $cat = $this->getDataGenerator()->create_category(['name' => 'North']);
        $course = $this->getDataGenerator()->create_course(['category' => $cat->id]);
        school::save((int) $cat->id, true, 'north.example');
        $ada = $this->getDataGenerator()->create_user([
            'email' => 'ada@north.example',
            'firstname' => 'Ada',
            'lastname' => 'Moss',
        ]);
        $this->getDataGenerator()->enrol_user($ada->id, $course->id, 'student');

        $this->assertTrue(school::email_allowed('ada@north.example'));
        $this->assertTrue(school::email_allowed('ada@class.north.example'));
        $this->assertFalse(school::email_allowed('ada@south.example'));
        $this->assertFalse(school::email_allowed('ada@south.example', (int) $ada->id));
        $this->assertTrue(school::email_allowed('ada@north.example', (int) $ada->id));

        \curl::mock_response($this->discovery());
        $issuer = \core\oauth2\api::create_standard_issuer('microsoft');
        $info = [
            'username' => 'ada.south',
            'email' => 'ada@south.example',
            'firstname' => 'Ada',
            'lastname' => 'Moss',
        ];
        $client = $this->createMock(\core\oauth2\client::class);
        $client->method('get_raw_userinfo')->willReturn((object) $info);
        $client->method('get_userinfo')->willReturn($info);
        $client->method('get_issuer')->willReturn($issuer);
        $client->method('log_out');

        $this->setUser(0);
        $auth = new \auth_oauth2\auth();
        try {
            @$auth->complete_login($client, new \moodle_url('/'));
            $this->fail('The other school domain should not sign in');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->errorcode);
        }
        $this->assertFalse(\core_user::get_user_by_email('ada@south.example'));
        $this->assertTrue($DB->record_exists('user', ['id' => $ada->id]));
    }

    /**
     * Userinfo is read from a mocked response, not from Google.
     */
    public function test_google_userinfo_uses_mocked_http(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        \curl::mock_response($this->discovery());
        $issuer = \core\oauth2\api::create_standard_issuer('google');
        $issuer->set('clientid', 'not-a-real-client');
        $issuer->set('clientsecret', 'not-a-real-secret');
        $issuer->update();

        $scopes = \core\oauth2\api::get_system_scopes_for_issuer($issuer);
        $account = new \core\oauth2\system_account(0, (object) [
            'issuerid' => $issuer->get('id'),
            'refreshtoken' => 'not-a-real-refresh-token',
            'grantedscopes' => $scopes,
            'email' => 'sys@example.com',
            'username' => 'sys',
        ]);
        $account->create();

        \curl::mock_response(json_encode([
            'sub' => '100',
            'email' => 'ada@north.example',
            'given_name' => 'Ada',
            'family_name' => 'Moss',
        ]));
        \curl::mock_response(json_encode([
            'access_token' => 'not-a-real-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]));

        $client = \core\oauth2\api::get_system_oauth_client($issuer);
        $this->assertNotFalse($client);
        $info = $client->get_userinfo();
        $this->assertSame('ada@north.example', $info['email']);
        $this->assertSame('Ada', $info['firstname']);
    }

    /**
     * A saved avatar is the face. A student without one gets initials.
     */
    public function test_face_uses_a_saved_avatar(): void {
        $this->resetAfterTest();
        [, , $user] = $this->class_with_student();

        $plain = code::face($user);
        $this->assertStringContainsString('AM', $plain);
        $this->assertStringNotContainsString('<svg', $plain);

        $this->setUser($user);
        \local_wwpoints\avatar::save((int) $user->id, ['clothing' => 'red']);
        $drawn = code::face($user);
        $this->assertStringContainsString('<svg', $drawn);
        $this->assertStringContainsString('#d64545', $drawn);
        $this->assertStringNotContainsString('AM', $drawn);
    }

    /**
     * A suspended enrolment cannot use the class page.
     */
    public function test_suspended_enrolment_cannot_sign_in(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setUser(0);
        [$course, $token, $user] = $this->class_with_student();
        $picks = ['sun', 'tree', 'fish'];
        code::set((int) $user->id, $picks);
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $user->id]);

        $this->assertSame('denied', code::sign_in($course, $token, (int) $user->id, $picks));
        $this->assertNotEquals($user->id, $USER->id ?? 0);
    }

    /**
     * Turning the picture plugin off closes the class page.
     */
    public function test_disabled_plugin_cannot_sign_in(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setUser(0);
        [$course, $token, $user] = $this->class_with_student();
        $picks = ['sun', 'tree', 'fish'];
        code::set((int) $user->id, $picks);
        \core\plugininfo\auth::enable_plugin('wwpicture', 0);

        $this->assertFalse(code::open($course, $token));
        $this->assertSame('closed', code::sign_in($course, $token, (int) $user->id, $picks));
        $this->assertNotEquals($user->id, $USER->id ?? 0);
    }

    /**
     * A linked account is checked against its own school, even when the external email matches another school.
     */
    public function test_mapped_account_uses_its_own_school(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $north = $this->getDataGenerator()->create_category(['name' => 'North']);
        $course = $this->getDataGenerator()->create_course(['category' => $north->id]);
        school::save((int) $north->id, true, 'north.example');
        $south = $this->getDataGenerator()->create_category(['name' => 'South']);
        school::save((int) $south->id, true, 'other.example');
        $ada = $this->getDataGenerator()->create_user([
            'email' => 'ada@north.example',
            'auth' => 'manual',
            'confirmed' => 1,
        ]);
        $this->getDataGenerator()->enrol_user($ada->id, $course->id, 'student');

        \curl::mock_response($this->discovery());
        $issuer = \core\oauth2\api::create_standard_issuer('google');
        $info = [
            'username' => 'ada-external',
            'email' => 'ada@other.example',
            'firstname' => 'Ada',
            'lastname' => 'Moss',
        ];
        \auth_oauth2\api::link_login($info, $issuer, (int) $ada->id, true);

        $client = $this->createMock(\core\oauth2\client::class);
        $client->method('get_raw_userinfo')->willReturn((object) $info);
        $client->method('get_userinfo')->willReturn($info);
        $client->method('get_issuer')->willReturn($issuer);
        $client->method('log_out');

        $this->setUser(0);
        $auth = new \auth_oauth2\auth();
        try {
            @$auth->complete_login($client, new \moodle_url('/'));
            $this->fail('The linked account is in the other school');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->errorcode);
        }
        $this->assertNotEquals($ada->id, $USER->id ?? 0);
    }

    /**
     * One enabled school, one student, and the class link.
     *
     * @param string $school
     * @param string $firstname
     * @return array
     */
    private function class_with_student(string $school = 'North', string $firstname = 'Ada'): array {
        \core\plugininfo\auth::enable_plugin('wwpicture', 1);
        $courseid = $this->school_course($school);
        $user = $this->getDataGenerator()->create_user(['firstname' => $firstname, 'lastname' => 'Moss']);
        $this->getDataGenerator()->enrol_user($user->id, $courseid, 'student');
        return [$courseid, code::token($courseid), $user];
    }

    /**
     * @param string $name
     * @return int
     */
    private function school_course(string $name): int {
        $cat = $this->getDataGenerator()->create_category(['name' => $name]);
        $course = $this->getDataGenerator()->create_course(['category' => $cat->id, 'shortname' => $name]);
        school::save((int) $cat->id, true, '');
        return (int) $course->id;
    }

    /**
     * Render the WolfWhale sign-in form with the buttons decided above.
     *
     * @return string
     */
    private function login_html(): string {
        global $PAGE, $SITE;

        $PAGE->reset_theme_and_output();
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url(new \moodle_url('/login/index.php'));
        $PAGE->set_course($SITE);
        $PAGE->force_theme('wolfwhale');
        $PAGE->initialise_theme_and_output();
        // PHPUnit uses the command-line renderer. The web sign-in screen uses this one.
        $renderer = new \theme_wolfwhale\output\core_renderer($PAGE, RENDERER_TARGET_GENERAL);
        return $renderer->render_login(new \core_auth\output\login(['manual']));
    }

    /**
     * A fake discovery document. create_standard_issuer reads this instead of the network.
     *
     * @return string
     */
    private function discovery(): string {
        return json_encode([
            'issuer' => 'https://accounts.google.com',
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scopes_supported' => ['openid', 'email', 'profile'],
        ]);
    }
}
