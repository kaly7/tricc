#!/usr/bin/perl
use strict;
use warnings;
use DBI;

my @job_ids = @ARGV;

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
print $out "send \"queueShow \\r\"\n";
print $out "expect {\n";
print $out "    \"EndQueueShow\" {}\n";
print $out "    timeout {}\n";
print $out "}\n";
print $out "close\n";
close($out);

system("expect $fleet_file");

# Log parse – job pickup-ok és robot állapotok kinyerése
my @job_map;      # job_id => { status, pickups => [...] }
my %job_index;    # job_id => index in @job_map
my @robot_parts;  # JSON stringek
my @robots_db;    # hash ref-ek DB update-hez
my @fm_jobs_list; # queueShow összes aktív pickup
my $raw = '';
my @fm_lines;

if (open(my $log_fh, "<", $raw_log)) {
    while (my $line = <$log_fh>) {
        chomp $line;
        $raw .= $line . "\n";

        if ($line =~ /QueueQuery:|EndQueueQuery|QueueShow:|EndQueueShow|QueueRobot:|EndQueueShowRobot|QueueError|Connection refused|telnet:|Enter password/i) {
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

        # queueShow: összes aktív pickup az FM-ben
        # Formátum: QueueShow: PICKUP123 JOB_001 1 InProgress None Goal "GOAL" "Kiss_Gyuri" 05/20/2026 10:00:00 ...
        if ($line =~ /QueueShow:\s+(\S+)\s+(\S+)\s+\d+\s+(\S+)\s+\S+\s+\S+\s+"([^"]*)"\s+"([^"]*)"\s+(\d+\/\d+\/\d+)\s+(\d+:\d+:\d+)/) {
            my ($pickup_id, $job_id, $status, $goal, $robot, $date, $time)
                = ($1, $2, $3, $4, $5, $6, $7);
            push @fm_jobs_list, {
                pickup_id => $pickup_id,
                job_id    => $job_id,
                status    => $status,
                goal      => $goal,
                robot     => $robot,
                kezdes    => _conv_date($date, $time),
            };
        }

        # Robot állapot parse
        # Formátum: QueueRobot: "Kiss_Gyuri" Available Parked ""
        if ($line =~ /QueueRobot:\s+"([^"]+)"\s+(\S+)\s+(\S+)/) {
            my ($name, $avail, $fmstat) = ($1, $2, $3);
            push @robots_db, { name => $name, avail => $avail, fmstat => $fmstat };
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

my @fm_jobs_json_parts;
for my $p (@fm_jobs_list) {
    (my $s_pid = $p->{pickup_id}) =~ s/"/\\"/g;
    (my $s_jid = $p->{job_id})   =~ s/"/\\"/g;
    (my $s_st  = $p->{status})   =~ s/"/\\"/g;
    (my $s_g   = $p->{goal})     =~ s/"/\\"/g;
    (my $s_r   = $p->{robot})    =~ s/"/\\"/g;
    push @fm_jobs_json_parts, sprintf(
        '{"pickup_id":"%s","job_id":"%s","status":"%s","goal":"%s","robot":"%s","kezdes":"%s"}',
        $s_pid, $s_jid, $s_st, $s_g, $s_r, $p->{kezdes}
    );
}
my $fm_jobs_json = '[' . join(',', @fm_jobs_json_parts) . ']';
my $json = "{\"results\":$results_json,\"robots\":$robots_json,\"fm_jobs\":$fm_jobs_json}";

open(my $res_fh, ">", $result_file) or do { unlink $lock_file; exit 1; };
print $res_fh $json;
close($res_fh);

# Kommunikációs napló – csak érdemi sorok (teljes raw → query_raw.log, help lista nem kerül ide)
my $cmd_list = "queueShowRobot, queueShow";
if (@job_ids) {
    $cmd_list = "queueQuery×" . scalar(@job_ids) . ", " . $cmd_list;
}
my $log_entry = "[$ts] " . (@job_ids ? "Lekérdezés" : "Robot státusz") . " [$cmd_list]\n";
$log_entry   .= "  Job ID-k: " . (@job_ids ? join(", ", @job_ids) : "(nincs aktív job)") . "\n";
if (@fm_lines) {
    $log_entry .= "  FM válasz:\n";
    for my $line (@fm_lines) {
        $log_entry .= "    $line\n" if $line =~ /\S/;
    }
} else {
    $log_entry .= "  FM válasz: (üres – kapcsolati hiba?)\n";
}
if (@summary) {
    $log_entry .= "  Eredmény: " . join(", ", @summary) . "\n";
} else {
    $log_entry .= "  Eredmény: nincs befejezett job\n";
}
$log_entry .= "  fm_jobs_live: " . scalar(@fm_jobs_list) . " aktív pickup az FM-ben\n";
if (@robot_parts) {
    $log_entry .= "  Robotok: " . scalar(@robot_parts) . " db frissítve";
    for my $r (@robots_db) {
        $log_entry .= " | $r->{name}: $r->{avail} $r->{fmstat}";
    }
    $log_entry .= "\n";
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

# Robots tábla direkt frissítése DBI-val + státuszfájlok írása
if (@robots_db) {
    my $tmp_dir = '/var/www/html/pm/tmp';

    # DB frissítés
    eval {
        my $dbh = DBI->connect('DBI:mysql:Robot:localhost', 'robot', 'abrakadabra',
            { PrintError => 0, RaiseError => 1, AutoCommit => 1 });
        my $sth = $dbh->prepare(
            'UPDATE Robots SET availability=?, fm_status=?, frissitve=NOW() WHERE Robot_name=?'
        );
        for my $r (@robots_db) {
            $sth->execute($r->{avail}, $r->{fmstat}, $r->{name});
            my $rows = $sth->rows;
            if ($rows == 0) {
                if (open(my $el, '>>', $comm_log)) {
                    print $el "  [DB FIGYELEM] '$r->{name}' – 0 sor frissült (Robot_name nem egyezik?)\n";
                    close($el);
                }
            }
        }
        $dbh->disconnect();
    };
    if ($@) {
        if (open(my $el, '>>', $comm_log)) { print $el "  [DB hiba] $@\n"; close($el); }
    }

    # Státuszfájlok írása: Kiss_Gyuri → tmp/GYURI, Kiss_Marci → tmp/MARCI
    # A cron_poll.php ezekből szinkronizál ha a DBI nem érhető el
    for my $r (@robots_db) {
        my $last = (split /_/, $r->{name})[-1];
        my $fpath = "$tmp_dir/" . uc($last);
        if (open(my $sfh, '>', $fpath)) {
            print $sfh $r->{avail} . ' ' . $r->{fmstat};
            close($sfh);
        }
    }
}

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
