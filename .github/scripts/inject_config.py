import os
import re
import sys

# Path to the PHP script
file_path = 'cpanel_backup_s3.php'

try:
    with open(file_path, 'r') as f:
        content = f.read()
except FileNotFoundError:
    print(f"Error: {file_path} not found.")
    sys.exit(1)

# List of constants to replace (must match GitHub Actions environment variables)
constants = {
    'CPANEL_HOST': 'string',
    'CPANEL_PORT': 'int',
    'CPANEL_USER': 'string',
    'CPANEL_API_TOKEN': 'string',
    'S3_ENDPOINT': 'string',
    'S3_REGION': 'string',
    'S3_BUCKET': 'string',
    'S3_ACCESS_KEY': 'string',
    'S3_SECRET_KEY': 'string',
    'S3_PATH_PREFIX': 'string',
    'NOTIFY_EMAIL': 'string',
    'MAX_BACKUPS': 'int'
}

for var_name, var_type in constants.items():
    env_val = os.environ.get(var_name)
    if env_val is not None:
        # If it's a string, we wrap the injected value in single quotes
        if var_type == 'string':
            # Escape single quotes in the value to avoid breaking PHP syntax
            escaped_val = env_val.replace("'", "\\'")
            replacement = f"define('{var_name}', '{escaped_val}');"
        else:
            # For integers (like port or max backups), no quotes
            # Fallback to 0 if an empty string or non-numeric is passed unexpectedly
            numeric_val = env_val if env_val.isdigit() else "0"
            replacement = f"define('{var_name}', {numeric_val});"
            
        # Regex to find: define('VAR_NAME', 'some_value'); or define("VAR_NAME", 123);
        pattern = re.compile(rf"define\(\s*['\"]{var_name}['\"]\s*,\s*[^)]+\s*\);", re.IGNORECASE)
        
        # Check if matched
        if not pattern.search(content):
            print(f"Warning: {var_name} not found in the file.")
            
        content = pattern.sub(replacement, content)
    else:
        print(f"Warning: Environment variable {var_name} is not set. Keeping placeholder.")

with open(file_path, 'w') as f:
    f.write(content)

print("Configuration injected successfully.")
