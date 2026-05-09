#!/usr/bin/perl
use strict;
use warnings;

my @job_ids = @ARGV;
exit 0 unless @job_ids;

my $lock_file   = "/var/www/html/pm/tmp/query.lock";
my $fleet_file  = "/var/www/html/pm/tmp/talk_query.fleet";
my $raw_log     = "/var/www/html/pm/tmp/query_raw.log";
my $result_file = "/var/www/html/pm/tmp/query_result.json";
my $comm_log    = "/var/www/html/pm/tmp/query_comm.log";
my $max_log_bytes = 512 * 1024;  # 512 KB

# Lock ellenőrzés – ha fut egy másik példány, kilép
if (-e $lock_file && (time() - (stat($lock_file))[9]) < 30) {
    exit 0;
}
unlink $lock_file if -e $lock_file;
open(my $lock_fh, ">", $lock_file) or exit 1;
print $lock_fh $$;
close($lock_fh);

my $ts = do {
    my @t = localtime(time());
    sprintf("%04d-%02d-%02d %02d:%02d:%02d",
        $t[5]+1900, $t[4]+1, $t[3], $t[2], $t[1], $t[0]);
};

# Expect script generálás – egyetlen Telnet session, több queueQuery
open(my $out, ">", $fleet_file) or do { unlink $lock_file; exit 1; };
print $out "#!/usr/bin/expect\n";
print $out "log_file -noappend $raw_log\n";
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
my @fm_lines;  # releváns FM válasz sorok a naplóhoz
if (open(my $log_fh, "<", $raw_log)) {
    while (my $line = <$log_fh>) {
        chomp $line;
        # Releváns sorok gyűjtése a comm loghoz
        if ($line =~ /QueueQuery:|EndQueueQuery|QueueError|Connection refused|telnet:|Enter password/i) {
            push @fm_lines, $line;
        }
        # Státusz parse
        if ($line =~ /QueueQuery:\s+\S+\s+(\S+)\s+\S+\s+(Completed|Cancelled|Failed|Interrupted)/i) {
            my ($jid, $status) = ($1, lc($2));
            (my $safe = $jid) =~ s/"/\\"/g;
            push @parts, "{\"job_id\":\"$safe\",\"status\":\"$status\"}";
        }
    }
    close($log_fh);
}

# Ha nincs egyetlen releváns sor sem, loggoljuk a kapcsolati hibát
if (!@fm_lines) {
    if (open(my $log_fh, "<", $raw_log)) {
        my @all = <$log_fh>;
        close($log_fh);
        @fm_lines = map { chomp $_; $_ } @all[0..4] if @all;
        push @fm_lines, "(nincs FM válasz – kapcsolati hiba?)" unless @fm_lines;
    }
}

# Result JSON írása
my $json = "{\"results\":[" . join(",", @parts) . "]}";
open(my $res_fh, ">", $result_file) or do { unlink $lock_file; exit 1; };
print $res_fh $json;
close($res_fh);

# Kommunikációs napló bővítése
my @results_summary;
foreach my $p (@parts) {
    if ($p =~ /"job_id":"([^"]+)","status":"([^"]+)"/) {
        push @results_summary, "$1 → $2";
    }
}

my $log_entry = "[$ts] Lekérdezés\n";
$log_entry   .= "  Job ID-k: " . join(", ", @job_ids) . "\n";
$log_entry   .= "  FM válasz:\n";
$log_entry   .= "    $_\n" for @fm_lines;
if (@results_summary) {
    $log_entry .= "  Eredmény: " . join(", ", @results_summary) . "\n";
} else {
    $log_entry .= "  Eredmény: nincs befejezett job\n";
}
$log_entry .= "\n";

# Méretkorlát: ha túl nagy, a régi sorok levágatnak
if (-e $comm_log && (stat($comm_log))[7] > $max_log_bytes) {
    if (open(my $old_fh, "<", $comm_log)) {
        my @lines = <$old_fh>;
        close($old_fh);
        my $keep = int(scalar(@lines) * 0.6);
        @lines = @lines[-$keep..-1];
        if (open(my $new_fh, ">", $comm_log)) {
            print $new_fh "# ... régebbi bejegyzések levágva (méretkorlát) ...\n\n";
            print $new_fh @lines;
            close($new_fh);
        }
    }
}

open(my $comm_fh, ">>", $comm_log) or do { unlink $lock_file; exit 0; };
print $comm_fh $log_entry;
close($comm_fh);

unlink $lock_file;
