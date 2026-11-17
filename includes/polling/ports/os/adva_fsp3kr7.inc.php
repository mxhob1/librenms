<?php

/**
 * adva_fsp3kr7.inc.php
 *
 * LibreNMS ADVA FSP 3000 R7 port poller include
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2026 Michael Hobl
 * @author     Michael Hobl <michael@hobl.com.au>
 */

// The FSP 3000 R7 has no traffic counters in IF-MIB (no ifXTable, ifEntry octets are 0).
// Client ports only have frame and byte counts in the facility PM bins, which reset at
// the end of each PM interval. The current 1-day bin holds the completed 15-minute
// intervals of the day, so current 1-day + current 15-minute is a counter that only
// resets once a day (one unknown sample, as the port RRDs are DERIVE with a minimum of 0).
for ($attempt = 0; $attempt < 2; $attempt++) {
    $adva_pm = snmpwalk_cache_oid($device, 'frRcv4FacilityCurrent15minEntry', [], 'ADVA-FSPR7-PM-MIB');
    if (empty($adva_pm)) {
        break;
    }
    $adva_pm = snmpwalk_cache_oid($device, 'byteTrmtFacilityCurrent15minEntry', $adva_pm, 'ADVA-FSPR7-PM-MIB');
    $adva_pm = snmpwalk_cache_oid($device, 'frRcv4FacilityCurrent1dayEntry', $adva_pm, 'ADVA-FSPR7-PM-MIB');
    $adva_pm = snmpwalk_cache_oid($device, 'byteTrmtFacilityCurrent1dayEntry', $adva_pm, 'ADVA-FSPR7-PM-MIB');

    // If the 15-minute interval ended during the walks, the 1-day bin already holds the
    // 15-minute bin that was read and it would be counted twice. All bins share one
    // interval timer, so a lower elapsed time on a second read means walk again.
    $adva_elapsed = snmpwalk_cache_oid($device, 'frRcv4FacilityCurrent15minElapsedTime', [], 'ADVA-FSPR7-PM-MIB');
    $adva_rollover = false;
    foreach ($adva_elapsed as $index => $adva_elapsed_entry) {
        if ($adva_elapsed_entry['frRcv4FacilityCurrent15minElapsedTime'] < ($adva_pm[$index]['frRcv4FacilityCurrent15minElapsedTime'] ?? 0)) {
            $adva_rollover = true;
        }
    }

    if (! $adva_rollover) {
        break;
    }
}

if (! empty($adva_pm)) {
    // PM bins use the facility index (shelf.slot.port.ext.class), the matching ifIndex is entityFacilityOneIndex
    $adva_pm = snmpwalk_cache_oid($device, 'entityFacilityOneIndex', $adva_pm, 'ADVA-FSPR7-MIB');

    d_echo($adva_pm);

    foreach ($adva_pm as $index => $adva_pm_entry) {
        $nms_index = $adva_pm_entry['entityFacilityOneIndex'] ?? null;

        if ($nms_index === null || ! isset($port_stats[$nms_index])) {
            continue;
        }

        if (isset($adva_pm_entry['frRcv4FacilityCurrent1dayBytes'], $adva_pm_entry['frRcv4FacilityCurrent15minBytes'])) {
            $port_stats[$nms_index]['ifHCInOctets'] = $adva_pm_entry['frRcv4FacilityCurrent1dayBytes'] + $adva_pm_entry['frRcv4FacilityCurrent15minBytes'];
            $port_stats[$nms_index]['ifHCInUcastPkts'] = $adva_pm_entry['frRcv4FacilityCurrent1dayGoodFrames'] + $adva_pm_entry['frRcv4FacilityCurrent15minGoodFrames'];
            $port_stats[$nms_index]['ifInErrors'] = $adva_pm_entry['frRcv4FacilityCurrent1dayCrcFrames'] + $adva_pm_entry['frRcv4FacilityCurrent15minCrcFrames'];
        }

        if (isset($adva_pm_entry['byteTrmtFacilityCurrent1dayBytesTrmt'], $adva_pm_entry['byteTrmtFacilityCurrent15minBytesTrmt'])) {
            $port_stats[$nms_index]['ifHCOutOctets'] = $adva_pm_entry['byteTrmtFacilityCurrent1dayBytesTrmt'] + $adva_pm_entry['byteTrmtFacilityCurrent15minBytesTrmt'];
            $port_stats[$nms_index]['ifHCOutUcastPkts'] = $adva_pm_entry['byteTrmtFacilityCurrent1dayFramesTrmt'] + $adva_pm_entry['byteTrmtFacilityCurrent15minFramesTrmt'];
            $port_stats[$nms_index]['ifOutErrors'] = $adva_pm_entry['byteTrmtFacilityCurrent1dayCrcFramesEgress'] + $adva_pm_entry['byteTrmtFacilityCurrent15minCrcFramesEgress'];
        }
    }
}
