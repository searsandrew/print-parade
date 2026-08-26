namespace PrintParade.Bridge.Worker;

public sealed class ConsoleBridgeStatusSink : IBridgeStatusSink
{
    private readonly object sync = new();
    private readonly Queue<string> events = new();
    private Uri? serverUrl;
    private long? bridgeId;
    private string state = "Starting";
    private DateTimeOffset? lastPollAt;
    private DateTimeOffset? lastJobAt;
    private int jobsProcessed;
    private int warningCount;

    public void Started(Uri url)
    {
        serverUrl = url;
        state = "Connecting";
        AddEvent("Bridge worker started.");
    }

    public void Connected(long id)
    {
        bridgeId = id;
        state = "Connected / idle";
        AddEvent($"Connected as bridge {id}.");
    }

    public void Polled(bool processedJob)
    {
        lastPollAt = DateTimeOffset.Now;
        state = "Connected / idle";

        if (processedJob)
        {
            jobsProcessed++;
            lastJobAt = lastPollAt;
            AddEvent("A print job was processed.");
        }
        else
        {
            Render();
        }
    }

    public void Warning(string message)
    {
        warningCount++;
        state = "Connection problem / retrying";
        AddEvent(message);
    }

    public void Stopped()
    {
        state = "Stopped";
        AddEvent("Bridge worker stopped.");
    }

    private void AddEvent(string message)
    {
        lock (sync)
        {
            events.Enqueue($"{DateTimeOffset.Now:HH:mm:ss}  {message}");

            while (events.Count > 8)
            {
                events.Dequeue();
            }

            RenderLocked();
        }
    }

    private void Render()
    {
        lock (sync)
        {
            RenderLocked();
        }
    }

    private void RenderLocked()
    {
        if (!Console.IsOutputRedirected)
        {
            Console.Clear();
        }

        Console.WriteLine("PRINT PARADE BRIDGE MONITOR");
        Console.WriteLine(new string('=', 52));
        Console.WriteLine($"Status:          {state}");
        Console.WriteLine($"Server:          {serverUrl?.ToString() ?? "—"}");
        Console.WriteLine($"Bridge ID:       {bridgeId?.ToString() ?? "—"}");
        Console.WriteLine($"Last poll:       {FormatTime(lastPollAt)}");
        Console.WriteLine($"Last print job:  {FormatTime(lastJobAt)}");
        Console.WriteLine($"Jobs processed:  {jobsProcessed}");
        Console.WriteLine($"Warnings:        {warningCount}");
        Console.WriteLine();
        Console.WriteLine("RECENT ACTIVITY");
        Console.WriteLine(new string('-', 52));

        foreach (var entry in events)
        {
            Console.WriteLine(entry);
        }

        Console.WriteLine();
        Console.WriteLine("Press Ctrl+C to stop the foreground monitor.");
    }

    private static string FormatTime(DateTimeOffset? value) => value?.ToString("yyyy-MM-dd HH:mm:ss") ?? "—";
}
