<?php
// look in the current directory for files
define('FILES_DIRECTORY', __DIR__);

// ignore php, git, ht*, DS_* file extensions
define('EXTENSION_BLACKLIST', '/\\.(?:php|git.*|ht*|DS_.*|zip)$/i');

// get a list of all the files in this directory that aren't blacklisted
if (isset($_GET['list'])) {
    header('Content-Type: application/json; charset=utf-8');
    $files = [];
    if ($dir = opendir(FILES_DIRECTORY)) {
        while (($file = readdir($dir)) !== false) {
            if (!is_dir($file) && !preg_match(EXTENSION_BLACKLIST, $file)) {
                $basename = basename($file);
                $last_dot_pos = strrpos($basename, '.');
                $files[$basename] = [
                    'name' => $basename,
                    'size' => filesize($file),
                    'type' => mime_content_type($file),
                    'extension' => $last_dot_pos !== false ? strtolower(substr($basename, $last_dot_pos + 1)) : '',
                ];
            }
        }
        uksort($files, 'strnatcasecmp'); // case-insensitive sort
        $files = array_values($files); // un-key the array for better JSON
    }
    echo json_encode($files);
    exit;
}

// if the download was triggered, build the zip file
if (isset($_POST['submit']) && $_POST['submit'] === 'zip' && !empty($_POST['files'])) {
    if (count($_POST['files'])) {
        $zip = new ZipArchive();
        $date = date('Y-m-d @ gia');
        // create a zip file in the system's temp directory
        $zip_file = sys_get_temp_dir().DIRECTORY_SEPARATOR."Archive {$date}.zip";
        if ($zip->open($zip_file, ZipArchive::CREATE) !== true) {
            die("Cannot create temporary zip file.");
        }
        // add all the files (if allowed)
        foreach ($_POST['files'] as $name) {
            $name = basename($name); // filter out malicious ../ attacks
            $disk_name = FILES_DIRECTORY.DIRECTORY_SEPARATOR.$name;
            if (is_readable($disk_name) && !preg_match(EXTENSION_BLACKLIST, $name)) {
                $zip->addFile($disk_name, $name);
            }
        }
        $zip->close();
        // force download of the zip file
        header('Pragma: public'); // required
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Cache-Control: private', false); // required for certain browsers
        header('Content-Disposition: attachment; filename="'.str_replace('"', '\\"', basename($zip_file)).'";');
        header('Content-Type: application/zip');
        header('Content-Length: '.filesize($zip_file));
        readfile($zip_file);
        // delete the temp zip file
        unlink($zip_file);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download Zip Archive of Checked Files</title>
    <style>
    * { box-sizing: border-box; }
    body {
        font-family: -apple-system, system-ui, BlinkMacSystemFont, Roboto, "Helvetica Neue", Arial, sans-serif;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
        text-rendering: optimizeLegibility;
    }
    .notice { background-color: bisque; }
    button { font-size: inherit; }
    th { background-color: goldenrod; }
    td, th { padding: 2px 4px; }
    </style>
</head>
<body>
    <h1>Download Zip Archive of Checked Files</h1>
    <?php
    if (!extension_loaded('zip')) {
        echo '<p class="notice">The <a href="http://php.net/zip">PHP Zip extension</a> is not loaded. This tool will not work without it.</p>';
    }
    ?>
    <form method="POST">
        <p><button id="refresh">Refresh</button> <button id="check-all">Check all</button> <button id="check-none">Check none</button> <button id="check-toggle">Check toggle</button> <button id="download" name="submit" value="zip">Download checked</button></p>
        <table>
            <thead>
                <tr>
                    <th>File</th>
                    <th>Type</th>
                    <th>Size</th>
                </tr>
            </thead>
            <tbody id="o">
                <tr>
                    <td colspan="3" class="notice">Loading…</td>
                </tr>
            </tbody>
        </table>
    </form>

<script>
!(function () {
    var o = document.getElementById('o'), // output target for list of files
        checkboxes = []; // cache checkboxes in the DOM

    function loadFiles(ev) {
        console.log('loading files');
        ev && ev.preventDefault();
        o.innerHTML = '<tr><td class="notice" colspan="3">Loading…</td></tr>';
        // preserve existing checkmarks
        var already_checked = [];
        for (var i = 0; i < checkboxes.length; i++) {
            if (checkboxes[i].checked) {
                already_checked.push(checkboxes[i].value.toLowerCase());
            }
        }
        // load the list of files from the API
        fetch('./?list')
            .then(function (resp) { return resp.json(); })
            .then(function (json) {
                if (json && json.length) {
                    var all = [];
                    for (var i in json) {
                        var tr = '<tr>';
                        tr += '<td><label><input type="checkbox" name="files[]" value="' + json[i].name + '"' + (already_checked.indexOf(json[i].name.toLowerCase()) >= 0 ? ' checked' : '') + '> ' + json[i].name + '</label></td>';
                        tr += '<td>' + json[i].extension + '</td>';
                        tr += '<td>' + humanFileSize(json[i].size) + '</td>';
                        tr += '</tr>';
                        all.push(tr);
                    }
                    o.innerHTML = all.join('\n');
                    checkboxes = o.querySelectorAll('[type="checkbox"]');
                } else {
                    o.innerHTML = '<tr><td class="notice" colspan="3">No eligible files were found</td></tr>';
                }
            })
            .catch(function (err) { console.log('error fetching data', err); });
    }

    // From https://stackoverflow.com/a/20732091/1071925
    function humanFileSize(size) {
        var i = Math.floor(Math.log(size) / Math.log(1024));
        return (size / Math.pow(1024, i)).toFixed(1) * 1 + ' ' + ['B', 'kB', 'MB', 'GB', 'TB'][i];
    }

    // refresh button
    document.getElementById('refresh').addEventListener('click', loadFiles);

    // do initial page refresh
    loadFiles(null);

    // check all button
    document.getElementById('check-all').addEventListener('click', function (ev) {
        console.log('check all');
        ev.preventDefault();
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = true;
        }
    });

    // check none button
    document.getElementById('check-none').addEventListener('click', function (ev) {
        console.log('check none');
        ev.preventDefault();
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = false;
        }
    });

    // check toggle button
    document.getElementById('check-toggle').addEventListener('click', function (ev) {
        console.log('check toggle');
        ev.preventDefault();
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = !checkboxes[i].checked;
        }
    });
})();
</script>
</body>
</html>