const express = require('express');
const fs = require('fs');
const path = require('path');
const os = require('os');

const app = express();
const PORT = process.env.PORT || 8080;

// Get volume mount path from VCAP_SERVICES
const getVolumePath = () => {
    if (process.env.VCAP_SERVICES) {
        const vcapServices = JSON.parse(process.env.VCAP_SERVICES);
        const nfsService = vcapServices['nfs'] || vcapServices['nfs-volume'] || {};
        const serviceInstance = nfsService[0];
        if (serviceInstance && serviceInstance.volume_mounts && serviceInstance.volume_mounts[0]) {
            return serviceInstance.volume_mounts[0].container_dir;
        }
    }
    return '/var/vcap/data/nfs-test'; // fallback for local testing
};

const VOLUME_PATH = getVolumePath();
const TEST_FILE = path.join(VOLUME_PATH, 'test-data.txt');
const INSTANCE_FILE = path.join(VOLUME_PATH, `instance-${process.env.CF_INSTANCE_INDEX || '0'}.txt`);

app.use(express.json());

// Root endpoint - show status
app.get('/', (req, res) => {
    const status = {
        app_instance: process.env.CF_INSTANCE_INDEX || 'unknown',
        hostname: os.hostname(),
        volume_path: VOLUME_PATH,
        volume_exists: fs.existsSync(VOLUME_PATH),
        vcap_services: process.env.VCAP_SERVICES ? 'configured' : 'not configured'
    };

    res.json(status);
});

// Write to volume
app.post('/write', (req, res) => {
    const { message } = req.body;
    const timestamp = new Date().toISOString();
    const data = `[${timestamp}] Instance ${process.env.CF_INSTANCE_INDEX || '0'}: ${message || 'test'}\n`;

    try {
        // Ensure directory exists
        if (!fs.existsSync(VOLUME_PATH)) {
            return res.status(500).json({
                error: 'Volume not mounted',
                path: VOLUME_PATH
            });
        }

        // Append to shared file
        fs.appendFileSync(TEST_FILE, data);

        // Write instance-specific file
        fs.writeFileSync(INSTANCE_FILE, `Last write: ${timestamp}\n${message || 'test'}`);

        res.json({
            success: true,
            wrote: data,
            files: {
                shared: TEST_FILE,
                instance: INSTANCE_FILE
            }
        });
    } catch (error) {
        res.status(500).json({
            error: error.message,
            path: VOLUME_PATH
        });
    }
});

// Read from volume
app.get('/read', (req, res) => {
    try {
        const files = fs.readdirSync(VOLUME_PATH);
        const sharedData = fs.existsSync(TEST_FILE) ?
            fs.readFileSync(TEST_FILE, 'utf8') : 'No shared data yet';

        res.json({
            volume_path: VOLUME_PATH,
            files_in_volume: files,
            shared_data: sharedData,
            instance: process.env.CF_INSTANCE_INDEX || '0'
        });
    } catch (error) {
        res.status(500).json({
            error: error.message,
            path: VOLUME_PATH
        });
    }
});

// List all files in volume
app.get('/files', (req, res) => {
    try {
        const files = fs.readdirSync(VOLUME_PATH).map(file => {
            const stats = fs.statSync(path.join(VOLUME_PATH, file));
            return {
                name: file,
                size: stats.size,
                modified: stats.mtime
            };
        });

        res.json({
            volume_path: VOLUME_PATH,
            file_count: files.length,
            files: files
        });
    } catch (error) {
        res.status(500).json({
            error: error.message,
            path: VOLUME_PATH
        });
    }
});

app.listen(PORT, () => {
    console.log(`NFS Volume Test App running on port ${PORT}`);
    console.log(`Volume path: ${VOLUME_PATH}`);
    console.log(`Volume mounted: ${fs.existsSync(VOLUME_PATH)}`);
});