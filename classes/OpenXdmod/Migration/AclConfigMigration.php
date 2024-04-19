<?php

namespace OpenXdmod\Migration;

/**
 * A simple migration that just runs xdmod-acl-config.
 *
 * @package OpenXdmod\Migration
 */
class AclConfigMigration extends Migration
{

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $cmd = BIN_DIR . '/xdmod-acl-config';

        $output = shell_exec($cmd);
        $hadError = strpos($output, 'error') !== false;

        if ($hadError) {
            $this->logger->err($output);
        }
    }
}
