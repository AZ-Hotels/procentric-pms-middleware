// SUID wrapper to change the ProCentric PMS-Interface (Apache) listen port.
//
// Called by the Web-GUI (api.php, running as 'apache') when the configured
// pms_interface_port changes. Rewrites the dedicated Listen file and the
// VirtualHost port, validates with 'apachectl configtest' and reloads httpd.
// On any failure it restores the previous config (rollback). Port 80 (GUI)
// lives in httpd.conf and is never touched here.
//
// Build:   gcc -O2 -o pms-portctl pms-portctl.c
// Install: cp pms-portctl /usr/local/bin/ && chown root:root /usr/local/bin/pms-portctl
//          && chmod 4755 /usr/local/bin/pms-portctl
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <ctype.h>
#include <unistd.h>

#define LISTEN_FILE "/etc/httpd/conf.d/pms-listen.conf"
#define VHOST_FILE  "/etc/httpd/conf.d/pms-middleware.conf"

static void rollback(void) {
    system("cp -p " LISTEN_FILE ".bak-portctl " LISTEN_FILE " 2>/dev/null; "
           "cp -p " VHOST_FILE ".bak-portctl " VHOST_FILE " 2>/dev/null");
}

int main(int argc, char *argv[]) {
    if (argc != 2) {
        fprintf(stderr, "Usage: pms-portctl <port>\n");
        return 2;
    }

    // Strict validation: digits only, sane range. Prevents any shell injection
    // since the value is later embedded into system() commands.
    const char *p = argv[1];
    if (*p == '\0') { fprintf(stderr, "Leerer Port\n"); return 2; }
    for (const char *c = p; *c; c++) {
        if (!isdigit((unsigned char)*c)) {
            fprintf(stderr, "Ungueltiger Port (nur Ziffern erlaubt): %s\n", p);
            return 2;
        }
    }
    long port = strtol(p, NULL, 10);
    if (port < 1024 || port > 65535 || port == 443) {
        fprintf(stderr, "Port ausserhalb des erlaubten Bereichs (1024-65535): %ld\n", port);
        return 2;
    }

    setuid(0);
    setgid(0);

    char cmd[1024];

    // Backup current config
    system("cp -p " LISTEN_FILE " " LISTEN_FILE ".bak-portctl; "
           "cp -p " VHOST_FILE " " VHOST_FILE ".bak-portctl");

    // Rewrite the dedicated Listen directive (this file holds ONLY the pms port)
    snprintf(cmd, sizeof(cmd),
        "sed -ri 's/^[[:space:]]*Listen[[:space:]]+[0-9]+/Listen %ld/' " LISTEN_FILE, port);
    system(cmd);

    // Rewrite the VirtualHost port
    snprintf(cmd, sizeof(cmd),
        "sed -ri 's/(<VirtualHost[[:space:]]+\\*:)[0-9]+>/\\1%ld>/' " VHOST_FILE, port);
    system(cmd);

    // Verify both replacements actually took effect
    snprintf(cmd, sizeof(cmd),
        "grep -qE '^[[:space:]]*Listen[[:space:]]+%ld([[:space:]]|$)' " LISTEN_FILE
        " && grep -qE '<VirtualHost[[:space:]]+\\*:%ld>' " VHOST_FILE, port, port);
    if (system(cmd) != 0) {
        fprintf(stderr, "Portersetzung nicht verifizierbar - Rollback\n");
        rollback();
        return 1;
    }

    // Validate Apache config before applying
    if (system("/usr/sbin/apachectl configtest 2>&1") != 0) {
        fprintf(stderr, "apachectl configtest fehlgeschlagen - Rollback\n");
        rollback();
        return 1;
    }

    // Apply: graceful reload (port 80 / GUI keeps serving)
    if (system("/usr/bin/systemctl reload httpd") != 0) {
        fprintf(stderr, "httpd reload fehlgeschlagen - Rollback\n");
        rollback();
        system("/usr/bin/systemctl reload httpd");
        return 1;
    }

    printf("OK: PMS-Port auf %ld umgestellt\n", port);
    return 0;
}
