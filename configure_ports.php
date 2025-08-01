<?php
// Include database connection
include 'connection.php';
echo "Database connection established.\n";

// Fetch enabled ports for this collector
$collector_id = 1; // Placeholder; adjust logic if needed
$result = $remote_db->query("SELECT port FROM projects WHERE activation_key IS NOT NULL AND activation_key != '' AND port IS NOT NULL");
if (!$result) {
    die("Query failed: " . $remote_db->error . "\n");
}
echo "Query executed successfully.\n";

$ports = [];
while ($row = $result->fetch_assoc()) {
    $ports[] = $row['port'];
}
$remote_db->close();
echo "Fetched ports: " . implode(", ", $ports) . "\n";

if (empty($ports)) {
    die("No active ports found in the projects table.\n");
}

// Read existing MySQL admin password from environment variable
$mysql_admin_pw = getenv('MYSQL_ADMIN_PW') ?: 'Admin@collector1'; // Fallback to default
$exclude_host = getenv('EXCLUDE_HOST') ?: 'VM-748b2572-5bb2-499a-a4c8-17b6f7e01b67'; // Fallback

// Check if imtcp module is already loaded in 50-mysql.conf
$existing_config = file_get_contents("/etc/rsyslog.d/50-mysql.conf");
$rsyslog_config = "";
if (strpos($existing_config, 'module(load="imtcp")') === false) {
    $rsyslog_config .= "\n# Dynamic TCP port configuration\n";
    $rsyslog_config .= "module(load=\"imtcp\")\n";
}

// Generate rsyslog configuration for dynamic ports
foreach ($ports as $port) {
    $rsyslog_config .= "input(type=\"imtcp\" port=\"$port\" name=\"port$port\")\n";
    $rsyslog_config .= "if \$inputname == \"port$port\" and \$fromhost != \"$exclude_host\" then {\n";
    $rsyslog_config .= "    action(type=\"ommysql\" server=\"localhost\" db=\"syslog_db\" uid=\"Admin\" pwd=\"$mysql_admin_pw\" template=\"SqlFormat\")\n";
    $rsyslog_config .= "    action(type=\"omfile\" file=\"/var/log/remote_syslog.log\")\n";
    $rsyslog_config .= "}\n";
}

// Append to existing /etc/rsyslog.d/50-mysql.conf
file_put_contents("/etc/rsyslog.d/50-mysql.conf", $rsyslog_config, FILE_APPEND);
chmod("/etc/rsyslog.d/50-mysql.conf", 0644);
echo "rsyslog configuration appended to 50-mysql.conf.\n";

// Update firewall rules non-interactively
exec("sudo ufw allow 22/tcp 2>/dev/null");
foreach ($ports as $port) {
    exec("sudo ufw allow $port/tcp 2>/dev/null");
}
exec("sudo ufw --force enable 2>/dev/null");
echo "Firewall rules updated.\n";

// Restart rsyslog
exec("sudo systemctl restart rsyslog 2>/dev/null");
echo "rsyslog restarted.\n";

// Verify
exec("sudo netstat -tuln | grep -E '" . implode("|", $ports) . "'", $output, $return);
if ($return === 0) {
    echo "rsyslog restarted and ports are open: " . implode(", ", $ports) . "\n";
} else {
    echo "Error: Some ports not open. Check logs.\n";
    exec("sudo journalctl -u rsyslog | tail -20", $log_output);
    echo implode("\n", $log_output) . "\n";
}
?>