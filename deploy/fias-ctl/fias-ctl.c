// SUID wrapper for FIAS service control
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

int main(int argc, char *argv[]) {
    if (argc < 2) {
        printf("Usage: fias-ctl {start|stop|restart|status|pid}\n");
        return 1;
    }

    setuid(0);
    setgid(0);

    if (strcmp(argv[1], "start") == 0) {
        system("/usr/bin/systemctl enable fias-client 2>/dev/null");
        return system("/usr/bin/systemctl start fias-client");
    } else if (strcmp(argv[1], "stop") == 0) {
        system("/usr/bin/systemctl stop fias-client");
        return system("/usr/bin/systemctl disable fias-client 2>/dev/null");
    } else if (strcmp(argv[1], "restart") == 0) {
        return system("/usr/bin/systemctl restart fias-client");
    } else if (strcmp(argv[1], "status") == 0) {
        return system("/usr/bin/systemctl is-active fias-client");
    } else if (strcmp(argv[1], "pid") == 0) {
        return system("/usr/bin/systemctl show fias-client --property=MainPID --value");
    }

    printf("Unknown action: %s\n", argv[1]);
    return 1;
}
