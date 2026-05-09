#!/usr/bin/perl
use strict;
use warnings;

my @job_ids = @ARGV;
exit 0 unless @job_ids;

my $lock_file   = "/var/www/html/pm/tmp/query.lock";
my $fleet_file  = "/var/www/html/pm/tmp/talk_query.fleet";
my $log_file    = "/var/www/html/pm/tmp/query_raw.log";
my $result_file = "/var/www/html/pm/tmp/query_result.json";

# Lock ellenőrzés – ha fut egy másik példány, kilép
if (-e $lock_file && (time() - (stat($lock_file))[9]) < 30) {
    exit 0;
}
unlink $lock_file if -e $lock_file;
open(my $lock_fh, ">", $lock_file) or exit 1;
print $lock_fh $$;
close($lock_fh);

# Expect script generálás – egyetlen Telnet session, több queueQuery
open(my $out, ">", $fleet_file) or do { unlink $lock_file; exit 1; };
print $out "#!/usr/bin/expect\n";
print $out "log_file -noappend $log_file\n";
print $out "set timeout 5\n";
print $out "set host \"10.146.126.156\"\n";
print $out "set port \"7171\"\n";
print $out "set pass \"admin\"\n";
print $out "spawn telnet \$host \$port\n";
print $out "expect \"Enter \"\n";
print $out "send \"\$pass\\r\"\n";
print $out "expect \"End of\"\n";
foreach my $jid (@job_ids) {
    $jid =~ s/[^A-Za-z0-9_\-]//g;  # biztonsági szűrés
    print $out "send \"queueQuery jobId $jid \\r\"\n";
    print $out "expect {\n";
    print $out "    \"EndQueueQuery\" {}\n";
    print $out "    timeout {}\n";
    print $out "}\n";
}
print $out "close\n";
close($out);

system("expect $fleet_file");

# Log parse – Completed/Cancelled/Failed/Interrupted státuszok kinyerése
my @parts;
if (open(my $log_fh, "<", $log_file)) {
    while (my $line = <$log_fh>) {
        if ($line =~ /QueueQuery:\s+\S+\s+(\S+)\s+\S+\s+(Completed|Cancelled|Failed|Interrupted)/i) {
            my ($jid, $status) = ($1, lc($2));
            (my $safe = $jid) =~ s/"/\\"/g;
            push @parts, "{\"job_id\":\"$safe\",\"status\":\"$status\"}";
        }
    }
    close($log_fh);
}

my $json = "{\"results\":[" . join(",", @parts) . "]}";
open(my $res_fh, ">", $result_file) or do { unlink $lock_file; exit 1; };
print $res_fh $json;
close($res_fh);

unlink $lock_file;
