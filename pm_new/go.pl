#!/usr/bin/perl
open(IN,"/var/www/html/pm/tmp/newfile.txt");
$x=<IN>;
#chop($x);
#print "----->".$x."<-----\n";

open(OUT,">/var/www/html/pm/tmp/talk.fleet");

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

system("expect /var/www/html/pm/tmp/talk.fleet");


