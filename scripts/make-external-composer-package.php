#!/usr/bin/env php
<?php
if (!defined('ROOT')) {
    define('ROOT', realpath(__DIR__ . '/../'));
}

if (!function_exists('script_run_command')) {
    function script_run_command($command) {
        echo 'Running: ' . $command . PHP_EOL;
        return shell_exec($command);
    }
}

if (!function_exists('script_exit')) {
    function script_exit($reason) {
        echo $reason;
        echo PHP_EOL . PHP_EOL;
        exit -1;
    }
}

// check that we have a package name
if (!isset($_SERVER['argv'][1]) || !preg_match('#\w+#', $_SERVER['argv'][1])) {
    script_exit(__FILE__ . ' expects a package name as the only argument');
} else {
    $package_name = $_SERVER['argv'][1];
}

// did you copy config.ini.orig to the proper place?
if (!file_exists(ROOT . '/data/packager.ini')) {
    script_exit('packager.ini is required');
}

// load the ini
$ini = parse_ini_file(ROOT . '/data/packager.ini');

// does it look ok?
if (!file_exists($ini['external_components_path'] . '/' . $package_name)) {
    script_exit('External component ' . $package_name . ' was not found in ' . $ini['external_components_path']);
}


// check for release
if (!isset($ini['composer_release'])) {
    script_exit('The proper composer_release is required.');
}
$release = $ini['composer_release'];

// check for composer_url
if (!isset($ini['composer_url'])) {
    script_exit('The proper composer_url is required.');
}
$composer_url = $ini['composer_url'];


// check for vendor_name
if (!isset($ini['vendor_name'])) {
    script_exit('The proper vendor_name is required.');
}
$vendor_name = $ini['vendor_name'];


// MAKE A ZIP FILE?
if (array_key_exists('composer_create_zip', $ini)
    && (bool)$ini['composer_create_zip']) {

    // check for zip
    if (!file_exists($ini['zip_path'])) {
        script_exit('The path to zip does not look correct.');
    }
    $zip_path = $ini['zip_path'];

    $ext_component_path = rtrim($ini['external_components_path'], '\\/') . '/' . $package_name;
    $ext_component_library_path = $ext_component_path . '/library';

    // set cwd, just in case it was called from elsewhere
    chdir(realpath(ROOT . '/packages/working'));

    if (file_exists(ROOT . '/packages/working/' . $package_name)) {
        script_run_command('rm -Rf ' . ROOT . '/packages/working/' . $package_name);
    }

    $path = $package_name . '/src/' . $package_name;
    script_run_command('mkdir -p ' . $path);
    $command = 'cp -R ' . $ext_component_library_path . '/' . $package_name . '/* ' 
             . $path . '/';
    $output = script_run_command($command);

    // LICENSE file
    $command = 'cp -a ' . $ext_component_path . '/LICENSE.txt '
                 . $package_name . '/src/';
    $output = script_run_command($command);

    // write zip file
    chdir($package_name . '/src');
    $package_file_name = "{$package_name}-{$release}.zip";
    $package_file_base_uri = 'composer/';
    $command = 'zip -r ' . ROOT . '/public/composer/' . $package_file_name . ' .';
    script_run_command($command);
    $autoload = '';

} else {
    if (!isset($ini['release'])) {
        script_exit('The proper release is required.');
    }
    $pear_release = $ini['release'];
    $package_file_name = "{$package_name}-{$pear_release}.zip";
    $package_file_base_uri = 'get/';

    $autoload = "{$package_name}-{$pear_release}/php";

}

// Dependencies
$library_component_path = str_replace('_', '/', $package_name);
if (file_exists($ext_component_path . '/composer.json')) {
    $content  = file_get_contents($ext_component_path . '/composer.json');
    $composer = json_decode($content, true);
} else {
    $composer = array();
    $composer['name'] = strtolower(str_replace('_', '-', $package_name));
    $composer['name'] = 'zendframework/' . $composer['name'];
    $composer['license'] = 'BSD-3-Clause';
    $composer['keywords'] = array('zf2', strtolower(str_replace('Zend_', '', $package_name)));
    $composer['autoload']['psr-0'][str_replace('_', '\\', $package_name)] = $autoload;
    $composer['require']['php'] = ">=5.3.3";

    if (file_exists(ROOT . '/data/dependencies/' . $package_name . '.php')) {
        $dependency_file = ROOT . '/data/dependencies/' . $package_name . '.php';
    } elseif (file_exists(ROOT . '/data/dependencies/' . $package_name . '-scanned.php')) {
        $dependency_file = ROOT . '/data/dependencies/' . $package_name . '-scanned.php';
        $package_info = include $dependency_file;
    }

    if (isset($package_info) && $package_info) {
        if (isset($package_info['required'])) {
            foreach ($package_info['required'] as $dependency) {
                $dependency_name = strtolower(str_replace('_', '-', $dependency));
                $dependency_name = 'zendframework/' . $dependency_name;
                $composer['require'][$dependency_name] = "$release";
            }
        }
        if (isset($package_info['optional'])) {
            foreach ($package_info['optional'] as $dependency) {
                $dependency_name = strtolower(str_replace('_', '-', $dependency));
                $dependency_name = 'zendframework/' . $dependency_name;

                $suggest = str_replace('_', '\\', $dependency) . ' component';
                $composer['suggest'][$dependency_name] = $suggest;
            }
        }
    }
}

$composer['version'] = $release;

$composer['dist']['url'] = "$composer_url/{$package_file_base_uri}{$package_file_name}";
$composer['dist']['type'] = "zip";


// write composer file
$write = '<?php return ' . var_export($composer, true) . '; ?>';
$filename = "$release.part.php";
$path = ROOT . '/data/composer/' . $package_name. '/';
if (!is_dir($path)) {
    mkdir($path, 0777, true);
}

echo "Storing $path$filename" . PHP_EOL;
file_put_contents($path.$filename, $write);
