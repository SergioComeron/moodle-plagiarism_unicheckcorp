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
 * unicheck_archive.class.php
 *
 * @package     plagiarism_unicheck
 * @subpackage  plagiarism
 * @author      Aleksandr Kostylev <a.kostylev@p1k.co.uk>
 * @copyright   UKU Group, LTD, https://www.unicheck.com
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_unicheck\classes\entities;

use plagiarism_unicheck\classes\exception\unicheck_exception;
use plagiarism_unicheck\classes\plagiarism\unicheck_content;
use plagiarism_unicheck\classes\task\unicheck_upload_and_check_task;
use plagiarism_unicheck\classes\unicheck_api;
use plagiarism_unicheck\classes\unicheck_core;
use plagiarism_unicheck\classes\unicheck_notification;

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');
}

define('ARCHIVE_IS_EMPTY', 'Archive is empty or contains document(s) with no text');
define('ARCHIVE_CANT_BE_OPEN', 'Can\'t open zip archive');

/**
 * Class unicheck_archive
 *
 * @package     plagiarism_unicheck
 * @copyright   UKU Group, LTD, https://www.unicheck.com
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unicheck_archive {
    /**
     * @var \stored_file
     */
    private $file;
    /**
     * @var unicheck_core
     */
    private $core;

    /**
     * unicheck_archive constructor.
     *
     * @param \stored_file  $file
     * @param unicheck_core $core
     *
     * @throws unicheck_exception
     */
    public function __construct(\stored_file $file, unicheck_core $core) {
        $this->file = $file;
        $this->core = $core;
    }

    /**
     * Run check
     *
     * @return bool
     */
    public function run_checks() {
        global $DB;
        global $CFG;

        $archiveinternalfile = $this->core->get_plagiarism_entity($this->file)->get_internal_file();

        $ziparch = new \zip_archive();

        $tmpzipfile = tempnam($CFG->tempdir, 'unicheck_zip');
        $this->file->copy_content_to($tmpzipfile);
        if (!$ziparch->open($tmpzipfile, \file_archive::OPEN)) {
            $this->invalid_response($archiveinternalfile, ARCHIVE_CANT_BE_OPEN);

            return false;
        }

        $fileexist = false;
        foreach ($ziparch as $file) {
            if (!$file->is_directory) {
                $fileexist = true;
                break;
            }
        }

        if (!$fileexist) {
            $this->invalid_response($archiveinternalfile, ARCHIVE_IS_EMPTY);

            return false;
        }

        try {
            $this->process_archive_files($ziparch, $archiveinternalfile->id);
        } catch (\Exception $e) {
            mtrace('Archive error ' . $e->getMessage());
        }

        $archiveinternalfile->statuscode = UNICHECK_STATUSCODE_ACCEPTED;
        $archiveinternalfile->errorresponse = null;

        $DB->update_record(UNICHECK_FILES_TABLE, $archiveinternalfile);

        $ziparch->close();

        return true;
    }

    /**
     * Process archive files
     *
     * @param \zip_archive $ziparch
     * @param null         $parentid
     */
    private function process_archive_files(\zip_archive &$ziparch, $parentid = null) {
        global $CFG;

        $processed = array();
        foreach ($ziparch as $file) {
            if ($file->is_directory) {
                continue;
            }

            $name = fix_utf8($file->pathname);
            $tmpfile = tempnam($CFG->tempdir, 'unicheck_unzip');

            if (!$fp = fopen($tmpfile, 'wb')) {
                $this->unlink($tmpfile);
                $processed[$name] = 'Can not write temp file';
                continue;
            }

            if ($name === '' or array_key_exists($name, $processed)) {
                $this->unlink($tmpfile);
                continue;
            }

            if (!$fz = $ziparch->get_stream($file->index)) {
                $this->unlink($tmpfile);
                $processed[$name] = 'Can not read file from zip archive';
                continue;
            }

            $bytescopied = stream_copy_to_stream($fz, $fp);

            fclose($fz);
            fclose($fp);

            if ($bytescopied != $file->size) {
                $this->unlink($tmpfile);
                $processed[$name] = 'Can not read file from zip archive';
                continue;
            }

            $format = pathinfo($name, PATHINFO_EXTENSION);
            $plagiarismentity = new unicheck_content($this->core, null, $name, $format, $parentid);
            $plagiarismentity->get_internal_file();

            unicheck_upload_and_check_task::add_task(array(
                'tmpfile'   => $tmpfile,
                'filename'  => $name,
                'core'      => $this->core,
                'format'    => $format,
                'parent_id' => $parentid,
            ));
        }
    }

    /**
     * Restart check
     */
    public function restart_check() {
        global $DB;

        $internalfile = $this->core->get_plagiarism_entity($this->file)->get_internal_file();
        $childs = $DB->get_records_list(UNICHECK_FILES_TABLE, 'parent_id', array($internalfile->id));
        if ($childs) {
            foreach ((object) $childs as $child) {
                if ($child->check_id) {
                    unicheck_api::instance()->delete_check($child);
                }
            }

            unicheck_notification::success('plagiarism_run_success', true);

            $this->run_checks();
        }
    }

    /**
     * Delete file
     *
     * @param string $file
     */
    private function unlink($file) {
        if (!unlink($file)) {
            mtrace('Error deleting ' . $file);
        }
    }

    /**
     * Check response validation
     *
     * @param \stdClass $archivefile
     * @param string    $reason
     */
    private function invalid_response($archivefile, $reason) {
        global $DB;

        $archivefile->statuscode = UNICHECK_STATUSCODE_INVALID_RESPONSE;
        $archivefile->errorresponse = json_encode(array(
            array("message" => $reason),
        ));

        $DB->update_record(UNICHECK_FILES_TABLE, $archivefile);
    }
}
