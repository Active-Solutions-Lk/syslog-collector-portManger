<?php

// Database connection to remote server
include 'connection.php'; // Assuming connection.php contains the database connection code

// Fetch enabled ports for this collector (assume a collector_id is set)
$collector_id = 1; // Replace with dynamic collector ID if multiple collectors
$result = $remote_db->query("SELECT port FROM authorized_devices WHERE collector_id = $collector_id AND status = 'active'");
$ports = [];
while ($row = $result->fetch_assoc()) {
    $ports[] = $row['port'];
}
$remote_db->close();

// Generate rsyslog configuration
$rsyslog_config = "module(load=\"imudp\")\n";
foreach ($ports as $port) {
    $rsyslog_config .= "input(type=\"imudp\" port=\"$port\" name=\"port$port\")\n";
    $rsyslog_config .= "if \$inputname == \"port$port\" then {\n";
    $rsyslog_config .= "    action(type=\"ommysql\" server=\"localhost\" db=\"syslog_db\" uid=\"Admin\" pwd=\"Admin@collector1\" template=\"PortFormat\")\n";
    $rsyslog_config .= "    action(type=\"omfile\" file=\"/var/log/remote_syslog.log\")\n";
    $rsyslog_config .= "}\n";
}
$rsyslog_config .= "template(name=\"PortFormat\" type=\"string\" string=\"INSERT INTO remote_logs (received_at, hostname, facility, received_port, message) VALUES ('%timegenerated:::date-mysql%', '%hostname%', '%syslogfacility-text%', %$!inputname%, '%msg%')\")\n";

// Write to rsyslog configuration
file_put_contents("/etc/rsyslog.d/50-ports.conf", $rsyslog_config);
chmod("/etc/rsyslog.d/50-ports.conf", 0644);

// Update firewall rules
exec("sudo ufw --force reset"); // Reset to avoid conflicts (careful in production)
foreach ($ports as $port) {
    exec("sudo ufw allow $port/udp");
}
exec("sudo ufw enable");

// Restart rsyslog
exec("sudo systemctl restart rsyslog");

// Verify
exec("sudo netstat -tuln | grep -E '" . implode("|", $ports) . "'", $output, $return);
if ($return === 0) {
    echo "rsyslog restarted and ports are open: " . implode(", ", $ports) . "\n";
} else {
    echo "Error: Some ports not open. Check logs.\n";
    exec("sudo journalctl -u rsyslog | tail -20");
}
?>