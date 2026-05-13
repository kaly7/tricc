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

# Expect script generálás – egyetlen Telnet session, queueQuery + queueShowRobot
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
print $out "send \"queueShowRobot \\r\"\n";
print $out "expect {\n";
print $out "    \"EndQueueShowRobot\" {}\n";
print $out "    timeout {}\n";
print $out "}\n";
print $out "close\n";
close($out);

system("expect $fleet_file");

# Log parse – job pickup-ok és robot állapotok kinyerése
my @job_map;    # job_id => { status, pickups => [...] }
my %job_index;  # job_id => index in @job_map
my @robot_parts;
my $raw = '';
my @fm_lines;

if (open(my $log_fh, "<", $raw_log)) {
    while (my $line = <$log_fh>) {
        chomp $line;
        $raw .= $line . "\n";

        if ($line =~ /QueueQuery:|EndQueueQuery|QueueRobot:|EndQueueShowRobot|QueueError|Connection refused|telnet:|Enter password/i) {
            push @fm_lines, $line;
        }

        # Célpont-szintű parse
        # Formátum: QueueQuery: PICKUP1128 JOB_ID 10 Completed None Goal "KANBAN_FELV1" "Kiss_Gyuri" 05/13/2026 12:25:06 05/13/2026 12:27:44 "" 0
        if ($line =~ /QueueQuery:\s+(\S+)\s+(\S+)\s+\d+\s+(\w+)\s+\S+\s+\S+\s+"([^"]*)"\s+"([^"]*)"\s+(\d+\/\d+\/\d+)\s+(\d+:\d+:\d+)\s+(\S+)\s+(\S+)/) {
            my ($pickup_id, $job_id, $status, $goal, $robot,
                $start_d, $start_t, $end_d, $end_t)
                = ($1, $2, $3, $4, $5, $6, $7, $8, $9);

            # Dátum konverzió: MM/DD/YYYY HH:MM:SS → YYYY-MM-DD HH:MM:SS
            my $kezdes = _conv_date($start_d, $start_t);
            my $vegzes  = _conv_date($end_d,   $end_t);

            my $job_status = lc($status);

            # Job bejegyzés létrehozása ha még nincs
            unless (exists $job_index{$job_id}) {
                $job_index{$job_id} = scalar @job_map;
                push @job_map, { job_id => $job_id, status => $job_status, pickups => [] };
            }
            my $entry = $job_map[$job_index{$job_id}];

            # Legsúlyosabb státusz meghatározása job szinten
            $entry->{status} = _worse_status($entry->{status}, $job_status);

            # Escape JSON karakterek
            (my $s_pid  = $pickup_id) =~ s/"/\\"/g;
            (my $s_jid  = $job_id)   =~ s/"/\\"/g;
            (my $s_stat = $status)   =~ s/"/\\"/g;
            (my $s_goal = $goal)     =~ s/"/\\"/g;
            (my $s_rob  = $robot)    =~ s/"/\\"/g;

            push @{$entry->{pickups}}, {
                pickup_id => $s_pid,
                goal      => $s_goal,
                robot     => $s_rob,
                status    => $s_stat,
                kezdes    => $kezdes,
                vegzes    => $vegzes,
            };
        }

        # Robot állapot parse
        # Formátum: QueueRobot: "Kiss_Gyuri" Available Parked ""
        if ($line =~ /QueueRobot:\s+"([^"]+)"\s+(\w+)\s+(\w+)/) {
            my ($name, $avail, $fmstat) = ($1, $2, $3);
            (my $s_name  = $name)   =~ s/"/\\"/g;
            (my $s_avail = $avail)  =~ s/"/\\"/g;
            (my $s_fmst  = $fmstat) =~ s/"/\\"/g;
            push @robot_parts, "{\"name\":\"$s_name\",\"availability\":\"$s_avail\",\"fm_status\":\"$s_fmst\"}";
        }
    }
    close($log_fh);
}

if (!@fm_lines) {
    if (open(my $log_fh, "<", $raw_log)) {
        my @all = <$log_fh>;
        close($log_fh);
        @fm_lines = map { chomp $_; $_ } @all[0..4] if @all;
        push @fm_lines, "(nincs FM válasz – kapcsolati hiba?)" unless @fm_lines;
    }
}

# Result JSON összerakása
my @result_parts;
my @summary;
foreach my $entry (@job_map) {
    my $jid    = $entry->{job_id};
    my $jstat  = $entry->{status};
    (my $s_jid = $jid) =~ s/"/\\"/g;
    (my $s_st  = $jstat) =~ s/"/\\"/g;

    my @pickup_json;
    foreach my $p (@{$entry->{pickups}}) {
        push @pickup_json, sprintf(
            '{"pickup_id":"%s","goal":"%s","robot":"%s","status":"%s","kezdes":"%s","vegzes":"%s"}',
            $p->{pickup_id}, $p->{goal}, $p->{robot},
            $p->{status}, $p->{kezdes}, $p->{vegzes}
        );
    }
    my $pickups_json = '[' . join(',', @pickup_json) . ']';
    push @result_parts, "{\"job_id\":\"$s_jid\",\"status\":\"$s_st\",\"pickups\":$pickups_json}";
    push @summary, "$jid → $jstat";
}

my $robots_json  = '[' . join(',', @robot_parts) . ']';
my $results_json = '[' . join(',', @result_parts) . ']';
my $json = "{\"results\":$results_json,\"robots\":$robots_json}";

open(my $res_fh, ">", $result_file) or do { unlink $lock_file; exit 1; };
print $res_fh $json;
close($res_fh);

# Kommunikációs napló
my $log_entry = "[$ts] Lekérdezés\n";
$log_entry   .= "  Job ID-k: " . join(", ", @job_ids) . "\n";
$log_entry   .= "  Teljes FM session:\n";
for my $line (split /\n/, $raw) {
    $log_entry .= "    $line\n" if $line =~ /\S/;
}
if (@summary) {
    $log_entry .= "  Eredmény: " . join(", ", @summary) . "\n";
} else {
    $log_entry .= "  Eredmény: nincs befejezett job\n";
}
if (@robot_parts) {
    $log_entry .= "  Robotok: " . scalar(@robot_parts) . " db frissítve\n";
}
$log_entry .= "\n";

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

# --- Segédfüggvények ---

sub _conv_date {
    my ($date_str, $time_str) = @_;
    return '' unless $date_str && $time_str;
    # MM/DD/YYYY → YYYY-MM-DD
    if ($date_str =~ m{^(\d+)/(\d+)/(\d+)$}) {
        return sprintf("%04d-%02d-%02d %s", $3, $1, $2, $time_str);
    }
    return "$date_str $time_str";
}

sub _worse_status {
    my ($a, $b) = @_;
    my %rank = (completed => 1, pending => 2, inprogress => 3,
                interrupted => 4, cancelled => 4, failed => 5);
    my $ra = $rank{lc($a // '')} // 0;
    my $rb = $rank{lc($b // '')} // 0;
    return $rb > $ra ? $b : $a;
}
