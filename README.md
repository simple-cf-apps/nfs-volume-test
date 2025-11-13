# NFS Volume Test Application for Elastic Application Runtime

A PHP application to test NFSv3 volume services in Elastic Application Runtime foundations.

## Features

- Test NFS volume mounting and access
- Write and read files from shared NFS storage
- Multi-instance testing to verify volume sharing
- REST API endpoints for volume operations

## Prerequisites

- Cloud Foundry CLI installed
- Access to an Elastic Application Runtime environment with NFS volume services enabled
- An NFS server with an exported share

## Deployment Steps

### 1. Create an org and space for testing
```bash
# Create the org and space
cf create-org nfs-org
cf create-space nfs-space -o nfs-org

# Target the org and space
cf target -o nfs-org -s nfs-space
```

### 2. Create the NFS volume service instance
```bash
# Set your NFS server details
NFS_SERVER="192.168.50.224"
EXPORT_PATH="/srv/nfs/tas-volumes"

# Create the service instance
cf create-service nfs Existing nfs-volume \
  -c "{
    \"share\": \"${NFS_SERVER}${EXPORT_PATH}\",
    \"mount\": \"/var/vcap/data/nfs-test\"
  }"
```

Replace the `NFS_SERVER` and `EXPORT_PATH` variables with your actual NFS server IP/hostname and export path.

### 3. Wait for service to be ready
```bash
cf services
```

### 4. Push the application
```bash
cf push
```

The app will automatically bind to the service due to the manifest configuration.

### 5. Check the app status
```bash
cf app nfs-volume-test
```

## Testing the Application

### Get the application URL
```bash
APP_URL=$(cf app nfs-volume-test | grep routes | awk '{print $2}')
```

### Available Endpoints

- `GET /` - Check application and volume status
- `POST /write` - Write data to the volume
- `GET /read` - Read data from the volume
- `GET /files` - List all files in the volume

### Test Commands
```bash
# Check status
curl https://$APP_URL/

# Write data
curl -X POST https://$APP_URL/write \
  -H "Content-Type: application/json" \
  -d '{"message": "Test from first request"}'

# Read data
curl https://$APP_URL/read

# List files
curl https://$APP_URL/files
```

### Multi-Instance Testing
```bash
# Scale up to test multi-instance sharing
cf scale nfs-volume-test -i 3

# Get the app GUID
APP_GUID=$(cf app nfs-volume-test --guid)

# Write from different instances
for i in {0..2}; do
  echo "--- Writing to instance $i ---"
  curl -X POST https://$APP_URL/write \
    -H "Content-Type: application/json" \
    -H "X-CF-APP-INSTANCE: $APP_GUID:$i" \
    -d "{\"message\": \"Test from instance $i\"}"
  echo ""
done

# Read from any instance - should see all writes
curl https://$APP_URL/read
```