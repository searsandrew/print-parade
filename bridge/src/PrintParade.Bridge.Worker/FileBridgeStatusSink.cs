using System.Text.Json;

namespace PrintParade.Bridge.Worker;

public sealed class FileBridgeStatusSink : IBridgeStatusSink
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web) { WriteIndented = true };
    private readonly object sync = new();
    private readonly string path;
    private BridgeStatusSnapshot snapshot = NewSnapshot("Starting");

    public FileBridgeStatusSink() : this(DefaultPath) { }

    public FileBridgeStatusSink(string path)
    {
        this.path = Path.GetFullPath(path);
    }

    public static string DefaultPath => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
        "Print Parade",
        "bridge-status.json");

    public void Started(Uri serverUrl) => Update(
        current => current with { State = "Connecting", ServerUrl = serverUrl },
        "Bridge worker started.");

    public void Connected(long bridgeId) => Update(
        current => current with { State = "Connected / idle", BridgeId = bridgeId },
        $"Connected as bridge {bridgeId}.");

    public void Polled(bool processedJob)
    {
        var now = DateTimeOffset.Now;
        Update(
            current => current with
            {
                State = "Connected / idle",
                LastPollAt = now,
                LastJobAt = processedJob ? now : current.LastJobAt,
                JobsProcessed = current.JobsProcessed + (processedJob ? 1 : 0),
            },
            processedJob ? "A print job was processed." : null);
    }

    public void Warning(string message) => Update(
        current => current with
        {
            State = "Connection problem / retrying",
            WarningCount = current.WarningCount + 1,
        },
        message);

    public void Stopped() => Update(current => current with { State = "Stopped" }, "Bridge worker stopped.");

    public static async Task<BridgeStatusSnapshot?> ReadAsync(
        string? path = null,
        CancellationToken cancellationToken = default)
    {
        var statusPath = Path.GetFullPath(path ?? DefaultPath);

        try
        {
            await using var stream = File.Open(statusPath, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
            return await JsonSerializer.DeserializeAsync<BridgeStatusSnapshot>(stream, JsonOptions, cancellationToken);
        }
        catch (IOException)
        {
            return null;
        }
        catch (UnauthorizedAccessException)
        {
            return null;
        }
        catch (JsonException)
        {
            return null;
        }
    }

    private void Update(Func<BridgeStatusSnapshot, BridgeStatusSnapshot> update, string? activity)
    {
        lock (sync)
        {
            var recentEvents = snapshot.RecentEvents.ToList();

            if (!string.IsNullOrWhiteSpace(activity))
            {
                recentEvents.Add($"{DateTimeOffset.Now:HH:mm:ss}  {activity}");
                recentEvents = recentEvents.TakeLast(8).ToList();
            }

            snapshot = update(snapshot) with
            {
                UpdatedAt = DateTimeOffset.Now,
                RecentEvents = recentEvents,
            };
            TryWriteSnapshot();
        }
    }

    private void TryWriteSnapshot()
    {
        try
        {
            var directory = Path.GetDirectoryName(path)
                ?? throw new InvalidOperationException("The bridge status path has no parent directory.");
            Directory.CreateDirectory(directory);
            var temporaryPath = path + ".tmp";
            File.WriteAllText(temporaryPath, JsonSerializer.Serialize(snapshot, JsonOptions));
            File.Move(temporaryPath, path, overwrite: true);
        }
        catch (IOException)
        {
            // Status reporting must never interrupt printing.
        }
        catch (UnauthorizedAccessException)
        {
            // Status reporting must never interrupt printing.
        }
    }

    private static BridgeStatusSnapshot NewSnapshot(string state) => new(
        state,
        null,
        null,
        null,
        null,
        0,
        0,
        DateTimeOffset.Now,
        []);
}
