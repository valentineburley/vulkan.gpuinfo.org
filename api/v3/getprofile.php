<?php
/* 		
*
* Vulkan hardware capability database back-end
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

/** Generate a Vulkan Profile schema compliant JSON for device simulation */

require './../../database/database.class.php';
require './../../includes/functions.php';
require './../../includes/mappings.php';
require './../../includes/vktypes.php';
	
if (!isset($_GET['id'])) {
    header('HTTP/ 400 missing_or');
    echo "No report id specified!";
    die();
}

DB::connect();
$reportid = $_GET['id'];	
$stmnt = DB::$connection->prepare("SELECT * from reports where id = :reportid");
$stmnt->execute([":reportid" => $reportid]);
if ($stmnt->rowCount() == 0) {
    DB::disconnect();
    header('HTTP/ 400 missing_or');
    echo "No report id specified!";
    die();
}
$row = $stmnt->fetch(PDO::FETCH_ASSOC);

class VulkanProfile {
    private $reportid = null;
    private $queue_families = [];
    private $formats = [];
    private $extensions = [];
    private $features = [];
    private $extension_features = [];
    private $properties = [];
    private $extension_properties = [];

    private $profile_name = 'device';
    private $profile_version = 1;
    private $device_name = null;
    private $api_version = null;
    
    public $json = null;
    private $json_schema = null;

    function __construct($reportid) {
        $this->reportid = $reportid;
    }

    private function loadSchema() {
        $file = file_get_contents('profile_schema.json');
        $this->json_schema = json_decode($file, true);
    }

    /** Some fields in the database tables are not part of the spec and need to be skipped at completly or skipped to be remapped later*/
    private function skipField($name, $version) {
        $skip_fields = [
            'reportid',
            'headerversion',
            'productModel',
            'productManufacturer',
            'deviceid',
            'devicename',
            'devicetype',
            'driverversion',
            'driverversionraw',
        ];
        if ($version == '1.0') {
            $skip_fields = array_merge($skip_fields, [
                'residencyAlignedMipSize',
                'residencyNonResidentStrict',
                'residencyStandard2DBlockShape',
                'residencyStandard2DMultisampleBlockShape',
                'residencyStandard3DBlockShape',
                'subgroupProperties.subgroupSize',
                'subgroupProperties.supportedStages',
                'subgroupProperties.supportedOperations',
                'subgroupProperties.quadOperationsInAllStages',
                'maxComputeWorkGroupCount[0]',
                'maxComputeWorkGroupCount[1]',
                'maxComputeWorkGroupCount[2]',
                'maxComputeWorkGroupSize[0]',
                'maxComputeWorkGroupSize[1]',
                'maxComputeWorkGroupSize[2]',
                'maxViewportDimensions[0]',
                'maxViewportDimensions[1]',
                'pointSizeRange[0]',
                'pointSizeRange[1]',
                'viewportBoundsRange[0]',
                'viewportBoundsRange[1]',
                'lineWidthRange[0]',
                'lineWidthRange[1]',
            ]);
        }
        return in_array($name, $skip_fields);
    }    

    private function readFeatures($version) {
        $table_names = [
            '1.0' => 'devicefeatures',
            '1.1' => 'devicefeatures11',
            '1.2' => 'devicefeatures12',
            '1.3' => 'devicefeatures13',
        ];
        $table = $table_names[$version];
        $stmnt = DB::$connection->prepare("SELECT * from $table where reportid = :reportid");
        $stmnt->execute([":reportid" => $this->reportid]);
        $result = $stmnt->fetch(PDO::FETCH_ASSOC);
        if ($stmnt->rowCount() == 0) {
            return null;
        }
        $features = [];
        foreach ($result as $key => $value) {
            if ($this->skipField($key, $version)) {
                continue;
            }
            $features[$key] = boolval($value);
        }
        return count($features) > 0 ? $features : null;
    }

    /** Vulkan 1.0 device limits */
    private function readDeviceLimits() {
        $limit_stmnt = DB::$connection->prepare('SELECT * from devicelimits where reportid = :reportid');
        $limit_stmnt->execute([":reportid" => $this->reportid]);
        $limit_result = $limit_stmnt->fetch(PDO::FETCH_ASSOC);
        foreach ($limit_result as $limit_key => $limit_value) {
            if ($this->skipField($limit_key, '1.0')) {
                continue;
            }
            $type = VkTypes::$VkPhysicalDeviceLimits[$limit_key];
            $limits[$limit_key] = $this->convertValue($limit_value, $type, $limit_key);
        }    
        
        $limitToArray = function($name, $dim, $type) use ($limit_result) {
            $values = [];
            for ($i = 0; $i < $dim; $i++) {
                if ($type == 'int') {
                    $values[] = intval($limit_result[$name.'['.$i.']']);
                };
                if ($type == 'float') {
                    $values[] = floatval($limit_result[$name.'['.$i.']']);
                };
            }
            return $values;
        };
    
        // Multi-dimensional arrays are stored as single columns in the database and need to be remapped        
        $limits['maxComputeWorkGroupCount'] = $limitToArray('maxComputeWorkGroupCount', 3, 'int');
        $limits['maxViewportDimensions'] = $limitToArray('maxViewportDimensions', 2, 'int');
        $limits['pointSizeRange'] = $limitToArray('pointSizeRange', 2, 'float');
        $limits['viewportBoundsRange'] = $limitToArray('viewportBoundsRange', 2, 'float');
        $limits['lineWidthRange'] = $limitToArray('lineWidthRange', 2, 'float');
    
        return $limits;
    }

    private function readProperties($version) {
        switch($version) {
            case '1.0':
                $table = 'deviceproperties';
                $type_mappings = VkTypes::$VkPhysicalDeviceProperties;
                break;
            case '1.1':
                $table = 'deviceproperties11';
                $type_mappings = VkTypes::$VkPhysicalDeviceVulkan11Properties;
                break;
            case '1.2':
                $table = 'deviceproperties12';
                $type_mappings = VkTypes::$VkPhysicalDeviceVulkan12Properties;
                break;
            case '1.3':
                $table = 'deviceproperties13';
                $type_mappings = VkTypes::$VkPhysicalDeviceVulkan13Properties;
                break;
        }
        $stmnt = DB::$connection->prepare("SELECT * from $table where reportid = :reportid");
        $stmnt->execute([":reportid" => $this->reportid]);
        $result = $stmnt->fetch(PDO::FETCH_ASSOC);
        if ($stmnt->rowCount() == 0) {
            return null;
        }
        $properties = [];
        foreach ($result as $key => $value) {
            if ($this->skipField($key, $version)) {
                continue;
            }
            $key_name = $key;
            if ($version == '1.0') {
                // Use non-human readable api version
                if ($key == 'apiversion') {
                    continue;
                }
                if ($key == 'apiversionraw') {
                    $key_name = 'apiVersion';
                }
                // Fix some spelling differences between spec and database
                if ($key == 'vendorid') {
                    $key_name = 'vendorID';
                }
            }
            if ($version == '1.3') {
                // Some member names are so long that they had to be truncated in the database, so we need to get the long names
                if (stripos($key_name, 'idp') == 0) {
                    $key_name = str_replace('idp', 'integerDotProduct', $key_name);
                }
            }
            $type = $type_mappings[$key_name];
            $properties[$key_name] = $this->convertValue($value, $type, $key_name);
        }
        if ($version == '1.0') {
            // Remap sparse properties into struct
            $sparse_stmnt = DB::$connection->prepare('SELECT residencyAlignedMipSize, residencyNonResidentStrict, residencyStandard2DBlockShape, residencyStandard2DMultisampleBlockShape, residencyStandard3DBlockShape from deviceproperties where reportid = :reportid');
            $sparse_stmnt->execute([":reportid" => $this->reportid]);
            $sparse_result = $sparse_stmnt->fetch(PDO::FETCH_ASSOC);
            foreach ($sparse_result as $sparse_key => $sparse_value) {
                $sparse_properties[$sparse_key] = boolval($sparse_value);
            }
            $properties['sparseProperties'] = $sparse_properties;
            // Append VK1.0 limits
            $properties['limits'] = $this->readDeviceLimits();
        }
        return $properties;        
    }

    function readExtensionFeatures() {
        // Build list of core api versions to skip based on device's api level
        $api_version_skip_list = [];
        $api_major = explode('.', $this->api_version)[0];
        $api_minor = explode('.', $this->api_version)[1];
        if ($api_minor >= 1) {
            $api_version_skip_list[] = 'VK_VERSION_1_1';
        }
        if ($api_minor >= 2) {
            $api_version_skip_list[] = 'VK_VERSION_1_2';
        }
        if ($api_minor >= 3) {
            $api_version_skip_list[] = 'VK_VERSION_1_3';
        }
        $stmnt = DB::$connection->prepare("SELECT extension, name, supported from devicefeatures2 where reportid = :reportid");
        $stmnt->execute([":reportid" => $this->reportid]);
        $result = $stmnt->fetchAll(PDO::FETCH_GROUP  | PDO::FETCH_ASSOC);
        $schema_features_list = $this->json_schema["properties"]["capabilities"]["additionalProperties"]["properties"]["features"]["properties"];
        foreach ($result as $key => $values) {
            // @todo: comment
            if (!array_key_exists($key, Mappings::$extensions)) {
                continue;
            }
            $ext = Mappings::$extensions[$key];
            if ($ext['struct_type_physical_device_features'] == '') {
                continue;
            }            
            // Skip extensions that are not defined in the current schema
            if (!key_exists($ext['struct_type_physical_device_features'], $schema_features_list)) {
                continue;
            }
            // Skip feature structs that have been promoted to a core version supported by the device
            if ($ext['promoted_to'] !== '') {
                if (stripos($ext['promoted_to'], 'VK_VERSION') !== false) {
                    if (in_array($ext['promoted_to'], $api_version_skip_list)) {
                        continue;
                    }
                }
            }
            // @todo: only include those not part of the reports api version (promotedto)
            $feature = null;
            foreach ($values as $value) {
                $feature[$value['name']] = boolval($value['supported']);
            }
            $this->extension_features[$ext['struct_type_physical_device_features']] = $feature;
        }
    }

    function convertValue($value, $type, $name = null) {
        $convert = function($value, $type) {
            switch($type) {
                case 'uint8_t':
                case 'uint16_t':
                case 'uint32_t':
                case 'int8_t':
                case 'int16_t':
                case 'int32_t':
                case 'size_t':
                    return intval($value);
                case 'float':
                    return floatval($value);
                case 'VkBool32':
                    return boolval($value);
                case 'VkSampleCountFlags':
                    return VkTypes::VkSampleCountFlags($value);
                case 'VkShaderStageFlags':
                    return VkTypes::VkShaderStageFlags($value);
                case 'VkSampleCountFlagBits':
                    return VkTypes::VkSampleCountFlagBits($value);
                case 'VkPointClippingBehavior':
                    return VkTypes::VkPointClippingBehavior($value);
                case 'VkSubgroupFeatureFlags':
                    return VkTypes::VkSubgroupFeatureFlags($value);
                case 'VkDriverId':
                    return VkTypes::VkDriverId($value);
                case 'VkConformanceVersion':
                    return VkTypes::VkConformanceVersion($value);
                case 'VkResolveModeFlags':
                    return VkTypes::VkResolveModeFlags($value);
                case 'VkShaderFloatControlsIndependence':
                    return VkTypes::VkShaderFloatControlsIndependence($value);
                case 'VkExtent2D':
                    $arr = unserialize($value);                
                    return ['width' => $arr[0], 'height' => $arr[1]];
            }
            return $value;
        };
        if (!in_array($type, ['VkExtent2D', 'VkExtent3D']) && substr($value, 0, 2) == 'a:') {
            $arr = unserialize($value);
            $values = [];
            foreach($arr as $value) {
                $values[] = $convert($value, $type);
            }
            if ($name == "deviceLUID") {
                $values = array_slice($values, 0, 8);
            }
            return $values;
        } else {
            return $convert($value, $type);
        }
    }

    function readExtensionProperties() {
        // Build list of core api versions to skip based on device's api level
        $api_version_skip_list = [];
        $api_major = explode('.', $this->api_version)[0];
        $api_minor = explode('.', $this->api_version)[1];
        if ($api_minor >= 1) {
            $api_version_skip_list[] = 'VK_VERSION_1_1';
        }
        if ($api_minor >= 2) {
            $api_version_skip_list[] = 'VK_VERSION_1_2';
        }
        if ($api_minor >= 3) {
            $api_version_skip_list[] = 'VK_VERSION_1_3';
        }
        $stmnt = DB::$connection->prepare("SELECT extension, name, value from deviceproperties2 where reportid = :reportid");
        $stmnt->execute([":reportid" => $this->reportid]);
        $result = $stmnt->fetchAll(PDO::FETCH_GROUP  | PDO::FETCH_ASSOC);
        foreach ($result as $key => $values) {
            if (!array_key_exists($key, Mappings::$extensions)) {
                continue;
            }
            $ext = Mappings::$extensions[$key];
            if ($ext['struct_type_physical_device_properties'] == '') {
                continue;
            }            
            // Skip property structs that have been promoted to a core version supported by the device
            if ($ext['promoted_to'] !== '') {
                if (stripos($ext['promoted_to'], 'VK_VERSION') !== false) {
                    if (in_array($ext['promoted_to'], $api_version_skip_list)) {
                        continue;
                    }
                }
            }
            // @todo: get types for properties struct from schema
            $property = null;
            foreach ($values as $value) {
                $type = null;
                if (array_key_exists($value['name'], $ext['property_types'])) {
                    $type = $ext['property_types'][$value['name']];
                }
                $property[$value['name']] = $this->convertValue($value['value'], $type);
            }
            $this->extension_properties[$ext['struct_type_physical_device_properties']] = $property;
        }
    }        

    private function readExtensions() {
        $this->extensions = [];
        $stmnt = DB::$connection->prepare("SELECT name, specversion from deviceextensions de join extensions e on de.extensionid = e.id where reportid = :reportid");
        $stmnt->execute([":reportid" => $this->reportid]);
        $schema_extension_list = $this->json_schema["properties"]["capabilities"]["additionalProperties"]["properties"]["extensions"]["properties"];
        while ($row = $stmnt->fetch(PDO::FETCH_ASSOC)) {
            // Skip extensions that are not defined in the current schema
            if (!key_exists($row['name'], $schema_extension_list)) {
                continue;
            }
            $this->extensions[$row['name']] = intval($row['specversion']);
        }
    }

    private function readFormats() {
        $this->formats = [];
        $stmnt = DB::$connection->prepare("SELECT name, lineartilingfeatures, optimaltilingfeatures, bufferfeatures from deviceformats df join VkFormat vf on df.formatid = vf.value where reportid = :reportid and supported = 1 order by name asc");    
        $stmnt->execute([":reportid" => $this->reportid]);
        while ($row = $stmnt->fetch(PDO::FETCH_ASSOC)) {
            $format = [
                'VkFormatProperties' => [
                    'linearTilingFeatures' => VkTypes::VkFormatFeatureFlags($row['lineartilingfeatures']),
                    'optimalTilingFeatures' => VkTypes::VkFormatFeatureFlags($row['optimaltilingfeatures']),
                    'bufferFeatures' => VkTypes::VkFormatFeatureFlags($row['bufferfeatures'])
                ]
            ];
            $this->formats["VK_FORMAT_".$row['name']] = $format;
        }    
    }

    private function readQueueFamilies() {
        $this->queue_families = [];
        $stmnt = DB::$connection->prepare("SELECT * from devicequeues where reportid = :reportid");
        $stmnt->execute([":reportid" => $this->reportid]);
        while ($row = $stmnt->fetch(PDO::FETCH_ASSOC)) {
            $profile_queue_family = [
                'VkQueueFamilyProperties' => [
                    'queueFlags' => Vktypes::VkQueueFlags($row['flags']),
                    'queueCount' => intval($row['count']),
                    'timestampValidBits' => intval($row['timestampValidBits']),
                    'minImageTransferGranularity' => [
                        'width' => intval($row['minImageTransferGranularity.width']),
                        'height' => intval($row['minImageTransferGranularity.height']),
                        'depth' => intval($row['minImageTransferGranularity.depth']),
                    ]
                ]
            ];
            $this->queue_families[] = $profile_queue_family;
        }   
    }

    private function readDeviceInfo() {
        $stmnt = DB::$connection->prepare("SELECT * from reports where id = :reportid");
        $stmnt->execute([":reportid" => $this->reportid]);
        if ($stmnt->rowCount() == 0) {
            header('HTTP/ 400 missing_or');
            exit("Could not find report");
        }
        $row = $stmnt->fetch(PDO::FETCH_ASSOC);
        $this->device_name = $row['devicename'];
        $this->api_version = $row['apiversion'];
    }

    function generateJSON() {
        $api_versions =  ['1.0', '1.1', '1.2', '1.3'];

        DB::connect();
        $this->loadSchema();
        $this->readDeviceInfo();
        $this->readExtensions();
        foreach ($api_versions as $version) {
            $this->features[$version] = $this->readFeatures($version);
            $this->properties[$version] = $this->readProperties($version);
        }
        $this->readExtensionFeatures();
        $this->readExtensionProperties();
        $this->readFormats();
        $this->readQueueFamilies();
        DB::disconnect();

        $this->json['$schema'] = 'https://schema.khronos.org/vulkan/profiles-1.3.204.json#';        
        $this->json['profiles'] = [
            'GPUINFO_Exported_Profile' => [
                "version" => $this->profile_version,
                "api-version" => $this->api_version,
                "label" => "$this->device_name",
                "description" => "Exported from https://vulkan.gpuinfo.org",
                "contributors" => [],
                "history" => [],
                "capabilities" => [$this->profile_name]
            ]
        ];

        // Features
        foreach ($api_versions as $version) {
            $node_names = [
                '1.0' => ['requirement' => 'vulkan10requirements', 'struct' => 'VkPhysicalDeviceFeatures'],
                '1.1' => ['requirement' => 'vulkan11requirements', 'struct' => 'VkPhysicalDeviceVulkan11Features'],
                '1.2' => ['requirement' => 'vulkan12requirements', 'struct' => 'VkPhysicalDeviceVulkan12Features'],
                '1.3' => ['requirement' => 'vulkan13requirements', 'struct' => 'VkPhysicalDeviceVulkan13Features'],
            ];
            if (array_key_exists($version, $this->features) && ($this->features[$version] !== null) && count($this->features[$version]) > 0) {
                $this->json['capabilities'][$this->profile_name]['features'][$node_names[$version]['struct']] = $this->features[$version];
            }
        }
        if (count($this->extension_features) > 0) {
            foreach ($this->extension_features as $ext => $features) {
                $this->json['capabilities'][$this->profile_name]['features'][$ext] = $features;
            }
        }

        // Properties   
        foreach ($api_versions as $version) {
            $node_names = [
                '1.0' => ['requirement' => 'vulkan10requirements', 'struct' => 'VkPhysicalDeviceProperties'],
                '1.1' => ['requirement' => 'vulkan11requirements', 'struct' => 'VkPhysicalDeviceVulkan11Properties'],
                '1.2' => ['requirement' => 'vulkan12requirements', 'struct' => 'VkPhysicalDeviceVulkan12Properties'],
                '1.3' => ['requirement' => 'vulkan13requirements', 'struct' => 'VkPhysicalDeviceVulkan13Properties'],
            ];
            if (array_key_exists($version, $this->properties) && ($this->properties[$version] !== null) && count($this->properties[$version]) > 0) {
                $this->json['capabilities'][$this->profile_name]['properties'][$node_names[$version]['struct']] = $this->properties[$version];
            }
        }
        if (count($this->extension_properties) > 0) {
            foreach ($this->extension_properties as $ext => $features) {
                $this->json['capabilities'][$this->profile_name]['properties'][$ext] = $features;
            }
        }

        if ($this->extensions && (count($this->extensions) > 0)) {
            $this->json['capabilities'][$this->profile_name]['extensions'] = $this->extensions;
        } else {
            $this->json['capabilities'][$this->profile_name]['extensions'] = (object)null;
        }

        if ($this->formats && (count($this->formats) > 0)) {
            $this->json['capabilities'][$this->profile_name]['formats'] = $this->formats;
        } else {
            $this->json['capabilities'][$this->profile_name]['formats'] = (object)null;
        }

        if ($this->queue_families && (count($this->queue_families) > 0)) {
            $this->json['capabilities'][$this->profile_name]['queueFamiliesProperties'] = $this->queue_families;
        } else {
            $this->json['capabilities'][$this->profile_name]['queueFamiliesProperties'] = (object)null;
        }
    }
}
// Profile generation

$profile = new VulkanProfile($reportid);
$profile->generateJSON();

$filename = $device_name;
$filename = preg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $filename);
$filename = preg_replace("([\.]{2,})", '', $filename);	
$filename .= ".json";

header("Content-type: application/json");
// header("Content-Disposition: attachment; filename=".strtolower($filename));
echo json_encode($profile->json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

DB::disconnect();