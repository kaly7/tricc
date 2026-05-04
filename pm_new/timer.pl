#!/usr/bin/perl

$directory = '/var/www/html/pm/tmp';
@files = glob("$directory/*.fleet");

foreach  $file (@files) {
    print "$file\n";


    open(IN,$file);
    $x=<IN>;
    #chop($x);
    print "----->".$x."<-----\n";

    $log = '/var/www/html/pm/tmp/talk_timer.log';
    open( $fh,'>>', $log);
    print $fh $x."\n";
    close($fh);

    open(OUT,">/var/www/html/pm/tmp/talk.fleet_timer");

    print OUT "#!/usr/bin/expect \n";
    print OUT "set timeout 2\n";
    print OUT "set host \"10.146.126.156\" \n";
    print OUT "set port \"7171\" \n";
    print OUT "set pass \"admin\" \n";
    print OUT "spawn telnet \$host \$port \n";
    print OUT "expect \"Enter \" \n";
    print OUT "send \"\$pass\\r\" \n";
    print OUT "expect \"End of\" \n";
    print OUT "send \"".$x." \\r\" \n";
    print OUT "expect \"End\" \n";
    print OUT "close \n";

    close(OUT);

    system("expect /var/www/html/pm/tmp/talk.fleet_timer");
    sleep(2);
    system("rm $file");
}

