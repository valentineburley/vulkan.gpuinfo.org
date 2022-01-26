<?php

/**
 *
 * Vulkan hardware capability database server implementation
 *	
 * Copyright (C) 2016-2022 by Sascha Willems (www.saschawillems.de)
 *	
 * This code is free software, you can redistribute it and/or
 * modify it under the terms of the GNU Affero General Public
 * License version 3 as published by the Free Software Foundation.
 *	
 * Please review the following information to ensure the GNU Lesser
 * General Public License version 3 requirements will be met:
 * http://www.gnu.org/licenses/agpl-3.0.de.html
 *	
 * The code is distributed WITHOUT ANY WARRANTY; without even the
 * implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
 * PURPOSE.  See the GNU AGPL 3.0 for more details.		
 *
 */

 /*
  * Format listings are updated using a cronjob instead of being generated 
  * on demand due to the complex nature of the data
  */

include '../database/database.class.php';
include '../includes/functions.php';
include '../includes/constants.php';

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$start = microtime(true);

$total_count = 0;

DB::connect();

// Get the list of all currently available formats
$sql = "SELECT value, name from VkFormat";
$stmnt = DB::$connection->prepare($sql);
$stmnt->execute();
$format_names = $stmnt->fetchAll(PDO::FETCH_KEY_PAIR);

try {
    $apiversion = null;
    if (isset($_GET['apiversion'])) {
        $apiversion = $_GET['apiversion'];
    }
    foreach (['lineartiling', 'optimaltiling', 'buffer'] as $format_listing_type) {

        switch ($format_listing_type) {
            case 'lineartiling':
                $column = 'lineartilingfeatures';
                $parameter_name = 'lineartilingformat';
                $format_flags = $device_format_flags_tiling;
                break;
            case 'optimaltiling':
                $column = 'optimaltilingfeatures';
                $parameter_name = 'optimaltilingformat';
                $format_flags = $device_format_flags_tiling;
                break;
            case 'buffer':
                $column = 'bufferfeatures';
                $parameter_name = 'bufferformat';
                $format_flags = $device_format_flags_buffer;
                break;
        }

        $params = [];
        
        $api_version_filter = null;
        if ($apiversion !== null) {
            $params['apiversion'] = $apiversion;
            $api_version_filter = 'AND r.apiversion >= :apiversion';
        }

        $formats = [];
        $os_types = [];
        $sql = "SELECT formatid as name, r.ostype as ostype, count(distinct(r.displayname)) as coverage from reports r join deviceformats df on df.reportid = r.id
                where df.$column > 0 and df.$column & :value > 0 and r.ostype > -1
                $api_version_filter                    
                group by ostype, formatid
                order by ostype, formatid asc";
        $stmnt = DB::$connection->prepare($sql);
        foreach ($format_flags as $key => $format_name) {
            $params['value'] = $key;
            $stmnt->execute($params);
            $result = $stmnt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($result as $row) {
                $format_os = $row['ostype'];
                if (!in_array($format_os, $os_types)) {
                    $os_types[] = $format_os;
                }
                $formats[$format_os][$row['name']][$format_name] = $row['coverage'];
            }
            $total_count++;
        }

        // Generate html pages for each operating system
        foreach ($os_types as $ostype) {
            $platform = platformname($ostype);
            // @todo: apiversion
            $sql_count = "SELECT count(distinct(ifnull(r.displayname, dp.devicename))) from reports r join deviceproperties dp on dp.reportid = r.id where r.ostype = :ostype";
            $sql_count_params = ['ostype' => $ostype];
            if ($api_version_filter) {
                $sql_count .= " " . $api_version_filter;
                $sql_count_params['apiversion'] = $apiversion;
            }
            $deviceCount = DB::getCount($sql_count, $sql_count_params);

            ob_start();
            
            echo "<div class='tablediv' style='width:auto; display: inline-block;'>";
            echo "<table id='formats' class='table table-striped table-bordered table-hover responsive table-header-rotated format-table with-platform-selection'>";
            echo "<thead>";
            echo "  <tr>";
            echo "      <th>Format</th>";
            foreach ($format_flags as $key => $value) {
                echo "<th class='caption rotate-45'><div><span style='bottom: 30px'>$value</span></div></th>";
            }
            echo "  </tr>";
            echo "</thead>";
            echo "<tbody>";
                    
            foreach ($formats[$ostype] as $format_id => $format_coverage) {
                echo "<tr>";
                $format_name = $format_names[$format_id];
                echo "<td class='format-name'>" . $format_name . "</td>";
                foreach ($format_flags as $k => $v) {
                    $coverage = 0;
                    if (array_key_exists($v, $format_coverage)) {
                        $coverage = ($format_coverage[$v] / $deviceCount) * 100.0;
                    };
                    $class = ($coverage > 0) ? 'format-coverage-supported' : 'format-coverage-unsupported';
                    if ($coverage > 75.0) {
                        $class .= ' format-coverage-high';
                    } elseif ($coverage > 50.0) {
                        $class .= ' format-coverage-medium';
                    } elseif ($coverage > 0.0) {
                        $class .= ' format-coverage-low';
                    }
                    $link = "listdevicescoverage.php?$parameter_name=$format_name&featureflagbit=$v&platform=$platform";
                    echo "<td><a href='$link' class='$class'>" . round($coverage, 2) . "<span style='font-size:10px;'>%</span></a></td>";
                }
                echo "</tr>";
            }

            echo "  </tbody>";
            echo "</table>";
            echo "</div>";

            $html = ob_get_contents();
            ob_end_clean();

            $filename = "../static/".$parameter_name."_".$platform.".html";
            if ($apiversion !== null) {
                $filename = "../static/".$parameter_name."_".$platform."_".str_replace('.', '_', $apiversion).".html";
            }
            file_put_contents($filename, $html);
        }
    }
} catch (Exception $e) {
    echo "Error at generating format listings: ". $e->getMessage();
    exit();
}

DB::disconnect();

$end = microtime(true);
echo "success".PHP_EOL;
echo sprintf("Format listing generated: %d queries took %f seconds", $total_count, $end-$start);

