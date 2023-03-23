#!/usr/local/bin/php
<?php

/*
 * Copyright (C) 2023 Franco Fichtner <franco@opnsense.org>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

require_once 'config.inc';
require_once 'util.inc';
require_once 'interfaces.inc';

if (empty($argv[1])) {
    echo "requires a gateway name to operate on" . PHP_EOL;
    return;
}

$script = !empty($argv[2]) ? $argv[2] : null;

$poll = 1; /* live poll interval */
$wait = 10; /* startup and alarm delay */

$name = $argv[1];
$mode = null;

/* Alarm trigger conditions.  If both are false we run but never emit an alarm. */
/* XXX reload these from the config.xml periodically since we run forever */
$gw_switch_default = isset($config['system']['gw_switch_default']);
$gw_group_member = false;

foreach (config_read_array('gateways', 'gateway_group') as $group) {
    foreach ($group['item'] as $item) {
        $itemsplit = explode('|', $item);
        if ($itemsplit[0] == $name) {
            /* XXX consider trigger conditions later on */
            $gw_group_member = true;
            break;
        }
    }
}

sleep($wait);

while (1) {
    $report = ['status' => 'down'];
    $alarm = false;

    $status = return_gateways_status();
    if (!empty($status[$name])) {
        $report = $status[$name];

        /* wait for valid data before triggering an alarm */
        if ($report['loss'] == '~') {
            sleep($poll);
            continue;
        }
    }

    if ($script != null && !empty($mode) && $mode != $report['status']) {
        /* trigger monitor facility on all state changes */
        shell_safe("$script %s %s %s %s %s %s", [
            $report['name'],
            $report['monitor'],
            $mode . ' -> ' . $report['status'],
            $report['delay'],
            $report['stddev'],
            $report['loss']
        ]);
    }

    if ($gw_switch_default) {
        /* only consider down state transition in this case */
        if (!empty($mode) && $mode != $report['status'] && ($mode == 'down' || $report['status'] == 'down')) {
            $alarm = true;
        }
    }

    if ($gw_group_member) {
        /* XXX for now consider all state transitions as they depend on individual trigger setting not yet inspected */
        if (!empty($mode) && $mode != $report['status']) {
            $alarm = true;
        }
    }

    if ($alarm) {
        /* in actual alarm case run our required script to adapt to new situation */
        shell_safe('/usr/local/bin/flock -n -E 0 -o /tmp/filter_reload_gateway.lock configctl routes configure');
        sleep($wait);
    } else {
        sleep($poll);
    }

    $mode = $report['status'];
}
