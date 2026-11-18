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

namespace core_analytics;

use core_analytics\tests\mlbackend_helper_trait;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/test_indicator_max.php');
require_once(__DIR__ . '/fixtures/test_indicator_min.php');
require_once(__DIR__ . '/fixtures/test_indicator_null.php');
require_once(__DIR__ . '/fixtures/test_indicator_fullname.php');
require_once(__DIR__ . '/fixtures/test_indicator_random.php');
require_once(__DIR__ . '/fixtures/test_indicator_multiclass.php');
require_once(__DIR__ . '/fixtures/test_target_shortname.php');
require_once(__DIR__ . '/fixtures/test_target_shortname_multiclass.php');
require_once(__DIR__ . '/fixtures/test_static_target_shortname.php');
require_once(__DIR__ . '/../../course/lib.php');

/**
 * Unit tests for evaluation, training and prediction.
 *
 * NOTE: in order to execute this test using a separate server for the
 *       python ML backend you need to define these variables in your config.php file:
 *
 * define('TEST_MLBACKEND_PYTHON_HOST', '127.0.0.1');
 * define('TEST_MLBACKEND_PYTHON_PORT', 5000);
 * define('TEST_MLBACKEND_PYTHON_USERNAME', 'default');
 * define('TEST_MLBACKEND_PYTHON_PASSWORD', 'sshhhh');
 *
 * @package   core_analytics
 * @copyright 2017 David Monllaó {@link http://www.davidmonllao.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prediction_test extends \advanced_testcase {

    use mlbackend_helper_trait;

    /**
     * Purge all the mlbackend outputs.
     *
     * This is done automatically for mlbackends using the web server dataroot but
     * other mlbackends may store files elsewhere and these files need to be removed.
     *
     * @return null
     */
    public function tearDown(): void {
        $this->setAdminUser();

        $models = \core_analytics\manager::get_all_models();
        foreach ($models as $model) {
            $model->delete();
        }
        parent::tearDown();
    }

    /**
     * test_static_prediction
     *
     * @return void
     */
    public function test_static_prediction(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminuser();

        $model = $this->add_perfect_model('test_static_target_shortname');
        $model->enable('\core\analytics\time_splitting\no_splitting');
        $this->assertEquals(1, $model->is_enabled());
        $this->assertEquals(1, $model->is_trained());

        // No training for static models.
        $results = $model->train();
        $trainedsamples = $DB->get_records('analytics_train_samples', array('modelid' => $model->get_id()));
        $this->assertEmpty($trainedsamples);
        $this->assertEmpty($DB->count_records('analytics_used_files',
            array('modelid' => $model->get_id(), 'action' => 'trained')));

        // Now we create 2 hidden courses (only hidden courses are getting predictions).
        $courseparams = array('shortname' => 'aaaaaa', 'fullname' => 'aaaaaa', 'visible' => 0);
        $course1 = $this->getDataGenerator()->create_course($courseparams);
        $courseparams = array('shortname' => 'bbbbbb', 'fullname' => 'bbbbbb', 'visible' => 0);
        $course2 = $this->getDataGenerator()->create_course($courseparams);

        $result = $model->predict();

        // Var $course1 predictions should be 1 == 'a', $course2 predictions should be 0 == 'b'.
        $correct = array($course1->id => 1, $course2->id => 0);
        foreach ($result->predictions as $uniquesampleid => $predictiondata) {
            list($sampleid, $rangeindex) = $model->get_time_splitting()->infer_sample_info($uniquesampleid);

            // The range index is not important here, both ranges prediction will be the same.
            $this->assertEquals($correct[$sampleid], $predictiondata->prediction);
        }

        // 1 range for each analysable.
        $predictedranges = $DB->get_records('analytics_predict_samples', array('modelid' => $model->get_id()));
        $this->assertCount(2, $predictedranges);
        // 2 predictions for each range.
        $this->assertEquals(2, $DB->count_records('analytics_predictions',
            array('modelid' => $model->get_id())));

        // No new generated records as there are no new courses available.
        $model->predict();
        $predictedranges = $DB->get_records('analytics_predict_samples', array('modelid' => $model->get_id()));
        $this->assertCount(2, $predictedranges);
        $this->assertEquals(2, $DB->count_records('analytics_predictions',
            array('modelid' => $model->get_id())));
    }

    /**
     * test_model_contexts
     */
    public function test_model_contexts(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminuser();

        $misc = $DB->get_record('course_categories', ['name' => get_string('defaultcategoryname')]);
        $miscctx = \context_coursecat::instance($misc->id);

        $category = $this->getDataGenerator()->create_category();
        $categoryctx = \context_coursecat::instance($category->id);

        // One course per category.
        $courseparams = array('shortname' => 'aaaaaa', 'fullname' => 'aaaaaa', 'visible' => 0,
            'category' => $category->id);
        $course1 = $this->getDataGenerator()->create_course($courseparams);
        $course1ctx = \context_course::instance($course1->id);
        $courseparams = array('shortname' => 'bbbbbb', 'fullname' => 'bbbbbb', 'visible' => 0,
            'category' => $misc->id);
        $course2 = $this->getDataGenerator()->create_course($courseparams);

        $model = $this->add_perfect_model('test_static_target_shortname');

        // Just 1 category.
        $model->update(true, false, '\core\analytics\time_splitting\no_splitting', false, [$categoryctx->id]);
        $this->assertCount(1, $model->predict()->predictions);

        // Now with 2 categories.
        $model->update(true, false, false, false, [$categoryctx->id, $miscctx->id]);

        // The courses in the new category are processed.
        $this->assertCount(1, $model->predict()->predictions);

        // Clear the predictions generated by the model and predict() again.
        $model->clear();
        $this->assertCount(2, $model->predict()->predictions);

        // Course context restriction.
        $model->update(true, false, '\core\analytics\time_splitting\no_splitting', false, [$course1ctx->id]);

        // Nothing new as the course was already analysed.
        $result = $model->predict();
        $this->assertTrue(empty($result->predictions));

        $model->clear();
        $this->assertCount(1, $model->predict()->predictions);
    }

    /**
     * test_read_indicator_calculations
     *
     * @return void
     */
    public function test_read_indicator_calculations(): void {
        global $DB;

        $this->resetAfterTest(true);

        $starttime = 123;
        $endtime = 321;
        $sampleorigin = 'whatever';

        $indicator = $this->getMockBuilder('test_indicator_max')->onlyMethods(['calculate_sample'])->getMock();
        $indicator->expects($this->never())->method('calculate_sample');

        $existingcalcs = array(111 => 1, 222 => -1);
        $sampleids = array(111 => 111, 222 => 222);
        list($values, $unused) = $indicator->calculate($sampleids, $sampleorigin, $starttime, $endtime, $existingcalcs);
    }

    /**
     * test_not_null_samples
     */
    public function test_not_null_samples(): void {
        $this->resetAfterTest(true);

        $timesplitting = \core_analytics\manager::get_time_splitting('\core\analytics\time_splitting\quarters');
        $timesplitting->set_analysable(new \core_analytics\site());

        $ranges = array(
            array('start' => 111, 'end' => 222, 'time' => 222),
            array('start' => 222, 'end' => 333, 'time' => 333)
        );
        $samples = array(123 => 123, 321 => 321);

        $target = \core_analytics\manager::get_target('test_target_shortname');
        $indicators = array('test_indicator_null', 'test_indicator_min');
        foreach ($indicators as $key => $indicator) {
            $indicators[$key] = \core_analytics\manager::get_indicator($indicator);
        }
        $model = \core_analytics\model::create($target, $indicators, '\core\analytics\time_splitting\no_splitting');

        $analyser = $model->get_analyser();
        $result = new \core_analytics\local\analysis\result_array($model->get_id(), false, $analyser->get_options());
        $analysis = new \core_analytics\analysis($analyser, false, $result);

        // Samples with at least 1 not null value are returned.
        $params = array(
            $timesplitting,
            $samples,
            $ranges
        );
        $dataset = \core\test\phpunit\phpunit_util::call_internal_method(
            $analysis,
            'calculate_indicators',
            $params,
            \core_analytics\analysis::class,
        );
        $this->assertArrayHasKey('123-0', $dataset);
        $this->assertArrayHasKey('123-1', $dataset);
        $this->assertArrayHasKey('321-0', $dataset);
        $this->assertArrayHasKey('321-1', $dataset);


        $indicators = array('test_indicator_null');
        foreach ($indicators as $key => $indicator) {
            $indicators[$key] = \core_analytics\manager::get_indicator($indicator);
        }
        $model = \core_analytics\model::create($target, $indicators, '\core\analytics\time_splitting\no_splitting');

        $analyser = $model->get_analyser();
        $result = new \core_analytics\local\analysis\result_array($model->get_id(), false, $analyser->get_options());
        $analysis = new \core_analytics\analysis($analyser, false, $result);

        // Samples with only null values are not returned.
        $params = array(
            $timesplitting,
            $samples,
            $ranges
        );
        $dataset = \core\test\phpunit\phpunit_util::call_internal_method(
            $analysis,
            'calculate_indicators',
            $params,
            \core_analytics\analysis::class,
        );
        $this->assertArrayNotHasKey('123-0', $dataset);
        $this->assertArrayNotHasKey('123-1', $dataset);
        $this->assertArrayNotHasKey('321-0', $dataset);
        $this->assertArrayNotHasKey('321-1', $dataset);
    }

    /**
     * add_random_model
     *
     * @return \core_analytics\model
     */
    protected function add_random_model() {

        $target = \core_analytics\manager::get_target('test_target_shortname');
        $indicators = array('test_indicator_max', 'test_indicator_min', 'test_indicator_random');
        foreach ($indicators as $key => $indicator) {
            $indicators[$key] = \core_analytics\manager::get_indicator($indicator);
        }

        $model = \core_analytics\model::create($target, $indicators);

        // To load db defaults as well.
        return new \core_analytics\model($model->get_id());
    }

    /**
     * add_perfect_model
     *
     * @param string $targetclass
     * @return \core_analytics\model
     */
    protected function add_perfect_model($targetclass = 'test_target_shortname') {
        $target = \core_analytics\manager::get_target($targetclass);
        $indicators = array('test_indicator_max', 'test_indicator_min', 'test_indicator_fullname');
        foreach ($indicators as $key => $indicator) {
            $indicators[$key] = \core_analytics\manager::get_indicator($indicator);
        }

        $model = \core_analytics\model::create($target, $indicators);

        // To load db defaults as well.
        return new \core_analytics\model($model->get_id());
    }

    /**
     * Generates model for multi-classification
     *
     * @param string $targetclass
     * @return \core_analytics\model
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function add_multiclass_model($targetclass = 'test_target_shortname_multiclass') {
        $target = \core_analytics\manager::get_target($targetclass);
        $indicators = array('test_indicator_fullname', 'test_indicator_multiclass');
        foreach ($indicators as $key => $indicator) {
            $indicators[$key] = \core_analytics\manager::get_indicator($indicator);
        }

        $model = \core_analytics\model::create($target, $indicators);
        return new \core_analytics\model($model->get_id());
    }

    /**
     * Forces some configuration values.
     *
     * @param array $forcedconfig
     */
    protected function set_forced_config($forcedconfig) {
        \core_analytics\manager::reset_prediction_processors();

        if (empty($forcedconfig)) {
            return;
        }
        foreach ($forcedconfig as $pluginname => $pluginconfig) {
            foreach ($pluginconfig as $name => $value) {
                set_config($name, $value, $pluginname);
            }
        }
    }

    /**
     * Is the provided processor ready using the current configuration in the site?
     *
     * @param  string  $predictionsprocessorclass
     * @return \core_analytics\predictor
     */
    protected function is_predictions_processor_ready(string $predictionsprocessorclass) {
        // We repeat the test for all prediction processors.
        $predictionsprocessor = \core_analytics\manager::get_predictions_processor($predictionsprocessorclass, false);
        $ready = $predictionsprocessor->is_ready();
        if ($ready !== true) {
            $this->markTestSkipped('Skipping ' . $predictionsprocessorclass . ' as the predictor is not ready: ' . $ready);
        }

        return $predictionsprocessor;
    }
}
