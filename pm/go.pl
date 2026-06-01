#!/usr/bin/perl

my $cmd_file  = "/var/www/html/pm/tmp/newfile.txt";
my $fleet     = "/var/www/html/pm/tmp/talk.fleet";
my $raw_log   = "/var/www/html/pm/tmp/go_session.log";
my $comm_log  = "/var/www/html/pm/tmp/go_comm.log";
my $max_log_bytes = 512 * 1024;

open(my $in, "<", $cmd_file) or die "Cannot open $cmd_file: $!";
my $x = <$in>;
close($in);
chomp($x);

open(my $out, ">", $fleet) or die "Cannot open $fleet: $!";
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
print $out "send \"$x \\r\"\n";
print $out "expect {\n";
print $out "    \"End\" {}\n";
print $out "    timeout {}\n";
print $out "}\n";
print $out "close\n";
close($out);

system("expect $fleet");

# Timestamp
my ($sec,$min,$hour,$mday,$mon,$year) = localtime(time());
my $ts = sprintf("%04d-%02d-%02d %02d:%02d:%02d", $year+1900, $mon+1, $mday, $hour, $min, $sec);

# Raw session beolvasása
my $raw = "";
if (open(my $rfh, "<", $raw_log)) {
    local $/;
    $raw = <$rfh>;
    close($rfh);
}

# Kommunikációs napló bejegyzés
my $log_entry = "[$ts] Robot ide parancs\n";
$log_entry   .= "  Parancs: $x\n";
$log_entry   .= "  Telnet session:\n";
for my $line (split /\n/, $raw) {
    $log_entry .= "    $line\n";
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

open(my $cfh, ">>", $comm_log) or exit 0;
print $cfh $log_entry;
close($cfh);
