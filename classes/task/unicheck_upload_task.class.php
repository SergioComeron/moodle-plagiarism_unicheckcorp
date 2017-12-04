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
/**
 * unicheck_upload_task.class.php
 *
 * @package     plagiarism_unicheck
 * @author      Aleksandr Kostylev <a.kostylev@p1k.co.uk>
 * @copyright   UKU Group, LTD, https://www.unicheck.com
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_unicheck\classes\task;

use plagiarism_unicheck\classes\entities\providers\unicheck_file_provider;
use plagiarism_unicheck\classes\entities\unicheck_archive;
use plagiarism_unicheck\classes\exception\unicheck_exception;
use plagiarism_unicheck\classes\plagiarism\unicheck_content;
use plagiarism_unicheck\classes\services\storage\unicheck_file_state;
use plagiarism_unicheck\classes\unicheck_assign;
use plagiarism_unicheck\classes\unicheck_core;
use plagiarism_unicheck\classes\unicheck_settings;

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');
}

/**
 * Class unicheck_upload_task
 *
 * @package     plagiarism_unicheck
 * @subpackage  plagiarism
 * @author      Aleksandr Kostylev <a.kostylev@p1k.co.uk>
 * @copyright   UKU Group, LTD, https://www.unicheck.com
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unicheck_upload_task extends unicheck_abstract_task {

    /**
     * Key of pathname hash data parameter
     */
    const PATHNAME_HASH = 'pathnamehash';
    /**
     * Key of ucore data parameter
     */
    const UCORE_KEY = 'ucore';

    /**
     * @var unicheck_core
     */
    protected $ucore;

    /**
     * @var object
     */
    protected $internalfile;

    /**
     * Execute of adhoc task
     */
    public function execute() {
        $data = $this->get_custom_data();
        if (!is_object($data)) {
            return;
        }

        if (!property_exists($data, self::UCORE_KEY) || !property_exists($data, self::PATHNAME_HASH)) {
            return;
        }

        $this->ucore = new unicheck_core($data->ucore->cmid, $data->ucore->userid, $data->ucore->modname);
        if ($this->ucore->modname == UNICHECK_MODNAME_ASSIGN
            && (bool)unicheck_assign::get_by_cmid($this->ucore->cmid)->teamsubmission) {
            $this->ucore->enable_teamsubmission();
        }

        $file = get_file_storage()->get_file_by_hash($data->pathnamehash);
        $this->internalfile = $this->ucore->get_plagiarism_entity($file)->get_internal_file();

        if (!\plagiarism_unicheck::is_archive($file)) {
            $this->process_single_file($file);
            unset($this->ucore, $file);

            return;
        }

        try {
            $maxsupportedcount = unicheck_settings::get_assign_settings(
                $this->ucore->cmid,
                unicheck_settings::MAX_SUPPORTED_ARCHIVE_FILES_COUNT
            );

            if ($maxsupportedcount < unicheck_archive::MIN_SUPPORTED_FILES_COUNT ||
                $maxsupportedcount > unicheck_archive::MAX_SUPPORTED_FILES_COUNT) {
                $maxsupportedcount = unicheck_archive::DEFAULT_SUPPORTED_FILES_COUNT;
            }

            $supportedcount = 0;
            $extracted = (new unicheck_archive($file, $this->ucore))->extract();
            if (!count($extracted)) {
                throw new unicheck_exception(unicheck_exception::ARCHIVE_IS_EMPTY);
            }

            foreach ($extracted as $item) {
                if ($supportedcount > $maxsupportedcount) {
                    unicheck_archive::unlink($item['path']);
                    continue;
                }

                $this->process_archive_item($item);
                $supportedcount++;
            }

            if ($supportedcount < 1) {
                throw new unicheck_exception(unicheck_exception::ARCHIVE_IS_EMPTY);
            }
        } catch (\Exception $e) {
            unicheck_file_provider::to_error_state($this->internalfile, $e->getMessage());
            mtrace('Archive error ' . $e->getMessage());
        }

        unset($this->ucore, $file);
    }

    /**
     * Process archive item
     *
     * @param array $item
     */
    protected function process_archive_item(array $item) {
        $content = file_get_contents($item['path']);
        $plagiarismentity = new unicheck_content(
            $this->ucore,
            $content,
            $item['filename'],
            $item['format'],
            $this->internalfile->id
        );
        $internalfile = $plagiarismentity->get_internal_file();
        $internalfile->state = unicheck_file_state::UPLOADING;
        unicheck_file_provider::save($internalfile);

        $plagiarismentity->upload_file_on_server();

        unset($plagiarismentity, $content);

        unicheck_archive::unlink($item['path']);
    }

    /**
     * Process single stored file
     *
     * @param \stored_file $file
     */
    protected function process_single_file(\stored_file $file) {
        $plagiarismentity = $this->ucore->get_plagiarism_entity($file);
        $plagiarismentity->upload_file_on_server();
    }
}