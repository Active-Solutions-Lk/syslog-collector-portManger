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

// Generate rsyslog configuration
$rsyslog_config = "module(load=\"imtcp\")\n"; // Changed from imudp to imtcp
foreach ($ports as $port) {
    $rsyslog_config .= "input(type=\"imtcp\" port=\"$port\" name=\"port$port\")\n";
    $rsyslog_config .= "if \$inputname == \"port$port\" then {\n";
    $rsyslog_config .= "    action(type=\"ommysql\" server=\"localhost\" db=\"syslog_db\" uid=\"Admin\" pwd=\"Admin@collector1\" template=\"PortFormat\")\n";
    $rsyslog_config .= "    action(type=\"omfile\" file=\"/var/log/remote_syslog.log\")\n";
    $rsyslog_config .= "}\n";
}
$rsyslog_config .= "template(name=\"PortFormat\" type=\"string\" string=\"INSERT INTO remote_logs (received_at, hostname, facility, received_port, message) VALUES ('%timegenerated:::date-mysql%', '%hostname%', '%syslogfacility-text%', %$!inputname%, '%msg%')\")\n";
echo "rsyslog configuration generated.\n";

// Write to rsyslog configuration
file_put_contents("/etc/rsyslog.d/50-ports.conf", $rsyslog_config);
chmod("/etc/rsyslog.d/50-ports.conf", 0644);
echo "rsyslog configuration file written.\n";

// Update firewall rules non-interactively
// exec("sudo ufw --force reset 2>/dev/null"); // Commented out to avoid issues
foreach ($ports as $port) {
    exec("sudo ufw allow $port/tcp 2>/dev/null"); // Changed to tcp
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