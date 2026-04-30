#!/usr/bin/perl

use DBI;
#use strict;

my $driver = "mysql"; 
my $database = "Robot";
my $dsn = "DBI:$driver:database=$database";
my $userid = "robot";
my $password = "abrakadabra";

my $dbh = DBI->connect($dsn, $userid, $password ) or die $DBI::errstr;




$sql = " select Goal_name from Goals";
$sth = $dbh->prepare($sql);
$sth->execute();
$count = 0;
while ( @row = $sth->fetchrow_array() ) {
    @letezo_goal[$count] = @row[0];
    print @letezo_goal[$count]."\n";
    $count++;
}

#exit(0);


#	$sql = "DELETE FROM Goals";
#	print $sql."\n";

#	my $sth = $dbh->prepare($sql);
#	$sth->execute() or die $DBI::errstr;


system("/var/www/html/pm/goals.fleet > /tmp/goals.txt");
open(IN,"/tmp/goals.txt");




while(not(eof(IN) ) ) {

    $sor = <IN>;
    chop($sor);
    chop($sor);
    if (substr($sor,0,4) eq "Goal") {
	($a,$b) = split(":",$sor);
	print $b."\n";
	$van = 0;
	for ($i=0;$i<@letezo_goal;$i++) {
	    if ($b eq @letezo_goal[$i] ) { $van = 1; }
	}
    

	if ( $van == 0) {

	    $sql = "INSERT INTO Goals (Goal_name, active, Megjegyzes) values('".$b."', 'Y', '".$b."' )";
	    print $sql."\n";

	    my $sth = $dbh->prepare($sql);
	    $sth->execute() or die $DBI::errstr;
#	$sth->finish();
#	$dbh->commit or die $DBI::errstr;

	}

    }

}


